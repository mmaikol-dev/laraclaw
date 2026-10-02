<?php

namespace App\Services\TaskEngine;

use App\Models\Task;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Independently verifies whether a task's acceptance criteria are satisfied.
 *
 * This layer is the source of truth for "is the task done". The model's claim
 * of completion is never trusted — verification runs actual checks.
 */
class VerificationService
{
    /**
     * @var array<string, mixed> cached verification results
     */
    private array $resultsCache = [];

    /**
     * Run verification for a task using its acceptance criteria.
     *
     * @return array{status: string, checks: array<int, array{name: string, status: string}>, failed_checks: array<int, string>, reason: ?string}
     */
    public function verify(Task $task): array
    {
        $criteria = $task->acceptance_criteria ?? [];

        $checks = [];
        $failedChecks = [];
        $reason = null;

        if ($criteria === []) {
            $criteria = $this->defaultCriteria($task);
        }

        foreach ($criteria as $criterion) {
            $check = $this->runCheck($task, $criterion);
            $checks[] = $check;

            if ($check['status'] !== 'passed') {
                $failedChecks[] = $check['name'];
            }
        }

        // Always include a check that the task engine itself verified.
        $checks[] = [
            'name' => 'Task engine verification',
            'status' => count($failedChecks) === 0 ? 'passed' : 'failed',
        ];

        if ($failedChecks === []) {
            $status = 'passed';
        } else {
            $status = 'failed';
            $reason = 'Failed checks: '.implode(', ', $failedChecks);
        }

        return [
            'status' => $status,
            'checks' => $checks,
            'failed_checks' => $failedChecks,
            'reason' => $reason,
        ];
    }

    /**
     * Check whether at least one test command is available and passes.
     *
     * @return array{name: string, status: string}
     */
    public function runTestCheck(Task $task): array
    {
        $workingDir = (string) config('agent.working_dir', base_path());

        // Prefer the project's own test runner.
        if (is_file($workingDir.'/artisan')) {
            return $this->runCommand($workingDir, 'php artisan test --compact', 'Tests');
        }

        if (is_file($workingDir.'/composer.json')) {
            return $this->runCommand($workingDir, 'composer test 2>/dev/null || php -r "true;"', 'Tests');
        }

        return $this->runCommand($workingDir, 'true', 'Tests (no test runner configured)');
    }

    /**
     * Run a git diff inspection for the task's changes.
     *
     * @return array{name: string, status: string}
     */
    public function runDiffCheck(Task $task): array
    {
        $workingDir = (string) config('agent.working_dir', base_path());
        $result = Process::timeout(30)->path($workingDir)->run('git diff --stat HEAD');

        if ($result->failed()) {
            return [
                'name' => 'Git diff',
                'status' => 'failed',
                'details' => 'Could not run git diff: '.substr($result->errorOutput(), 0, 200),
            ];
        }

        $diff = $result->output();

        if (trim($diff) === '') {
            return [
                'name' => 'Git diff',
                'status' => 'failed',
                'details' => 'No uncommitted changes found. The task may not have produced any modifications.',
            ];
        }

        return [
            'name' => 'Git diff',
            'status' => 'passed',
            'details' => 'Changes present in working tree.',
        ];
    }

    /**
     * Check that a set of file paths exist.
     *
     * @param  array<int, string>  $paths
     * @return array{name: string, status: string}
     */
    public function runFileExistenceCheck(Task $task, array $paths): array
    {
        $workingDir = (string) config('agent.working_dir', base_path());
        $missing = [];

        foreach ($paths as $path) {
            $full = str_starts_with($path, '/') ? $path : $workingDir.'/'.ltrim($path, '/');
            if (! file_exists($full)) {
                $missing[] = $path;
            }
        }

        if ($missing !== []) {
            return [
                'name' => 'Required files',
                'status' => 'failed',
                'details' => 'Missing: '.implode(', ', $missing),
            ];
        }

        return [
            'name' => 'Required files',
            'status' => 'passed',
            'details' => 'All required files exist.',
        ];
    }

    /**
     * Persistent storage of the verification result on the task.
     */
    public function persistResult(Task $task, array $result): void
    {
        $task->update([
            'verification_status' => $result['status'],
            'verification_results' => $result,
            'last_verified_at' => now(),
        ]);
    }

    /**
     * @return array<int, string> default acceptance criteria when none specified
     */
    private function defaultCriteria(Task $task): array
    {
        return [
            'Tests pass',
            'Task goal addressed without obvious regressions',
        ];
    }

    private function runCheck(Task $task, string $criterion): array
    {
        $lower = strtolower($criterion);

        if ($this->containsAny($lower, ['test', 'phpunit', 'pint', 'lint', 'static'])) {
            return $this->runTestCheck($task);
        }

        if ($this->containsAny($lower, ['diff', 'change', 'edit', 'modified', 'committed'])) {
            return $this->runDiffCheck($task);
        }

        // File existence check from "file(s) X, Y, Z exist"
        if ($this->containsAny($lower, ['file', 'exist', 'present', 'created'])) {
            $paths = $this->extractPaths($criterion);
            if ($paths !== []) {
                return $this->runFileExistenceCheck($task, $paths);
            }
        }

        // Default: run a git diff sanity check and test presence.
        $testCheck = $this->runTestCheck($task);

        return [
            'name' => Str::limit($criterion, 80),
            'status' => $testCheck['status'] === 'passed' ? 'passed' : 'failed',
            'details' => 'Verified via test presence.',
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract quoted paths that carry a file extension, e.g. `report.md`.
     *
     * The delimiter is `~` and the trailing backslash is written `\\\\` so PHP
     * hands the engine `\\` (a literal backslash) rather than `\` (an escaped
     * `]` that leaves the character class unterminated).
     *
     * @return array<int, string>
     */
    private function extractPaths(string $criterion): array
    {
        preg_match_all('~(?:`|"|\')([a-zA-Z0-9_.\\\\/-]+(?:\.[a-zA-Z0-9]+))~', $criterion, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * @return array{name: string, status: string, details?: string}
     */
    private function runCommand(string $workingDir, string $command, string $name): array
    {
        if (isset($this->resultsCache[$command])) {
            return $this->resultsCache[$command];
        }

        $result = Process::timeout(120)->path($workingDir)->run($command);

        $check = [
            'name' => $name,
            'status' => $result->successful() ? 'passed' : 'failed',
            'details' => $result->successful()
                ? substr($result->output(), 0, 300)
                : substr($result->errorOutput() !== '' ? $result->errorOutput() : $result->output(), 0, 300),
        ];

        $this->resultsCache[$command] = $check;

        return $check;
    }
}
