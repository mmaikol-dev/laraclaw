<?php

namespace App\Services\Agent;

use App\Models\AgentMemory;
use App\Models\AgentSetting;
use App\Models\ProactiveFinding;
use App\Models\Project;
use App\Models\TaskLog;
use Illuminate\Support\Str;

class ProactiveMonitoringService
{
    /**
     * @param  array{
     *     working_dir: string,
     *     allowed_paths: array<int, string>,
     *     stack: array<int, string>,
     *     repo_signals: array<int, string>,
     *     disk: array{free_bytes: int, total_bytes: int, free_percent: float},
     *     git_repository: bool
     * }|null  $environment
     */
    public function buildPromptContext(?array $environment = null): string
    {
        $environment ??= $this->refreshEnvironmentAwareness();
        $stack = $environment['stack'] === [] ? 'unknown' : implode(', ', $environment['stack']);
        $signals = $environment['repo_signals'] === [] ? 'none detected' : implode(', ', $environment['repo_signals']);
        $allowedPaths = $environment['allowed_paths'] === [] ? 'none configured' : implode(', ', $environment['allowed_paths']);

        return "\n\n=== Environment Awareness ===\n"
            ."Working directory: {$environment['working_dir']}\n"
            ."Allowed paths: {$allowedPaths}\n"
            ."Detected stack: {$stack}\n"
            ."Repository signals: {$signals}\n"
            .'Git repository: '.($environment['git_repository'] ? 'yes' : 'no')."\n"
            ."Disk free: {$environment['disk']['free_percent']}%\n"
            .'Use this context before asking the user repeated environment questions. Re-check with tools before making risky or stale assumptions.';
    }

    /**
     * @return array{
     *     working_dir: string,
     *     allowed_paths: array<int, string>,
     *     stack: array<int, string>,
     *     repo_signals: array<int, string>,
     *     disk: array{free_bytes: int, total_bytes: int, free_percent: float},
     *     git_repository: bool
     * }
     */
    public function refreshEnvironmentAwareness(): array
    {
        $workingDirectory = $this->workingDirectory();
        $allowedPaths = $this->allowedPaths();
        $stack = $this->detectStack($workingDirectory);
        $repoSignals = $this->detectRepoSignals($workingDirectory);
        $disk = $this->detectDisk($workingDirectory);

        $summary = [
            'working_dir' => $workingDirectory,
            'allowed_paths' => $allowedPaths,
            'stack' => $stack,
            'repo_signals' => $repoSignals,
            'disk' => $disk,
            'git_repository' => is_dir($workingDirectory.'/.git'),
        ];

        $this->remember('environment.working_dir', $workingDirectory, 'environment', 'environment-monitor', 1.0, ['workspace']);
        $this->remember('environment.allowed_paths', implode(', ', $allowedPaths), 'environment', 'environment-monitor', 1.0, ['filesystem']);
        $this->remember('environment.stack', implode(', ', $stack), 'environment', 'environment-monitor', 0.95, ['stack']);
        $this->remember('environment.repo_signals', implode(', ', $repoSignals), 'environment', 'environment-monitor', 0.9, ['repository']);
        $this->remember('environment.disk', json_encode($disk, JSON_THROW_ON_ERROR), 'environment', 'environment-monitor', 0.9, ['resources']);

        return $summary;
    }

    /**
     * @return array<int, ProactiveFinding>
     */
    public function refreshFindings(): array
    {
        $environment = $this->refreshEnvironmentAwareness();
        $findings = [];

        if (($environment['disk']['free_percent'] ?? 100) < 15.0) {
            $findings[] = $this->upsertFinding(
                'disk-low-'.$environment['working_dir'],
                'resources',
                'high',
                'Workspace disk space is running low',
                'The configured working directory has less than 15% free space remaining.',
                'Low disk space can break builds, queues, logs, and model runs. Free space before continuing with larger jobs.',
                ['environment' => $environment],
            );
        }

        $recentErrors = TaskLog::query()
            ->where('status', 'error')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recentErrors >= 5) {
            $findings[] = $this->upsertFinding(
                'task-errors-last-day',
                'runtime',
                'medium',
                'Tool failures have been recurring',
                "There have been {$recentErrors} tool failures in the last 24 hours.",
                'Repeated tool failures usually indicate unstable environment assumptions, missing dependencies, or unsafe retry loops.',
                ['recent_error_count' => $recentErrors],
            );
        }

        $overdueProjects = Project::query()
            ->whereIn('status', ['pending', 'active'])
            ->whereDate('due_date', '<', today())
            ->count();

        if ($overdueProjects > 0) {
            $findings[] = $this->upsertFinding(
                'overdue-projects',
                'planning',
                'medium',
                'There are overdue active or pending projects',
                "{$overdueProjects} project(s) are still active or pending past their due date.",
                'These projects may need updated plans, scope adjustments, or explicit escalation.',
                ['overdue_projects' => $overdueProjects],
            );
        }

        return array_values(array_filter($findings));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function upsertFinding(
        string $fingerprint,
        string $category,
        string $severity,
        string $title,
        string $summary,
        string $details,
        array $meta = [],
    ): ProactiveFinding {
        return ProactiveFinding::query()->updateOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'category' => $category,
                'severity' => $severity,
                'status' => 'open',
                'title' => $title,
                'summary' => $summary,
                'details' => $details,
                'source' => 'proactive-monitor',
                'meta' => $meta,
                'detected_at' => now(),
                'resolved_at' => null,
            ],
        );
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function remember(
        string $key,
        string $value,
        string $category,
        string $source,
        float $confidence,
        array $tags = [],
    ): void {
        AgentMemory::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'category' => $category,
                'scope' => 'environment',
                'source' => $source,
                'confidence' => $confidence,
                'tags' => $tags,
                'last_observed_at' => now(),
            ],
        );
    }

    private function workingDirectory(): string
    {
        $configured = (string) AgentSetting::get('working_dir', config('agent.working_dir', base_path()));

        return $configured !== '' ? $configured : base_path();
    }

    /**
     * @return array<int, string>
     */
    private function allowedPaths(): array
    {
        return collect(explode(',', (string) AgentSetting::get('allowed_paths', config('agent.allowed_paths', ''))))
            ->map(fn (string $path): string => trim(str_replace('${HOME}', (string) config('agent.home_dir', ''), $path)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function detectStack(string $workingDirectory): array
    {
        $stack = [];

        if (is_file($workingDirectory.'/artisan') || is_file($workingDirectory.'/composer.json')) {
            $composer = is_file($workingDirectory.'/composer.json')
                ? (string) file_get_contents($workingDirectory.'/composer.json')
                : '';

            if (Str::contains($composer, 'laravel/framework')) {
                $stack[] = 'Laravel';
            }
        }

        if (is_file($workingDirectory.'/package.json')) {
            $package = (string) file_get_contents($workingDirectory.'/package.json');

            if (Str::contains($package, '"react"')) {
                $stack[] = 'React';
            }

            if (Str::contains($package, 'vite')) {
                $stack[] = 'Vite';
            }
        }

        if (is_file($workingDirectory.'/docker-compose.yml') || is_file($workingDirectory.'/compose.yaml') || is_file($workingDirectory.'/Dockerfile')) {
            $stack[] = 'Docker';
        }

        return array_values(array_unique($stack));
    }

    /**
     * @return array<int, string>
     */
    private function detectRepoSignals(string $workingDirectory): array
    {
        $signals = [];

        foreach ([
            '.git' => 'Git repository',
            '.env' => '.env present',
            'vendor' => 'Composer dependencies installed',
            'node_modules' => 'Node dependencies installed',
            'phpunit.xml' => 'PHP test suite configured',
            'vite.config.ts' => 'Vite TypeScript config present',
            'vite.config.js' => 'Vite config present',
        ] as $path => $label) {
            if (file_exists($workingDirectory.'/'.$path)) {
                $signals[] = $label;
            }
        }

        return $signals;
    }

    /**
     * @return array{free_bytes: int, total_bytes: int, free_percent: float}
     */
    private function detectDisk(string $workingDirectory): array
    {
        $free = @disk_free_space($workingDirectory);
        $total = @disk_total_space($workingDirectory);

        if (! is_numeric($free) || ! is_numeric($total) || (int) $total === 0) {
            return [
                'free_bytes' => 0,
                'total_bytes' => 0,
                'free_percent' => 100.0,
            ];
        }

        return [
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'free_percent' => round(((int) $free / (int) $total) * 100, 2),
        ];
    }
}
