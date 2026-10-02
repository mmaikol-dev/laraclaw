<?php

namespace App\Services\TaskEngine;

use App\Models\Task;
use App\Models\TaskCheckpoint;

/**
 * Detects pathological loops where the agent repeats the same action with the
 * same arguments, the same failure, or the same response, without progress.
 */
class LoopDetector
{
    /**
     * @var array<int, string> failure text fragments that a loop would repeat
     */
    private const FAILURE_SIGNALS = [
        'failed', 'error', 'not found', 'exception', 'timeout',
    ];

    /**
     * Returns an associative fingerprint for a checkpoint action to compare
     * against previous actions. Stable for "same action + same path", distinct
     * for different inputs.
     */
    public function fingerprint(string $action, ?string $result): string
    {
        return md5(strtolower($action).'|'.strtolower((string) $result));
    }

    /**
     * Detect whether the most recent checkpoints form a loop.
     *
     * Returns a normalized reason string describing the loop, or null if no
     * loop is detected.
     */
    public function detectLoop(Task $task, int $threshold): ?string
    {
        $checkpoints = $task->checkpoints()->latest()->limit($threshold)->get();

        if ($checkpoints->count() < $threshold) {
            return null;
        }

        $items = $checkpoints->map(
            fn (TaskCheckpoint $cp): array => [
                'action' => (string) $cp->action_taken,
                'result' => (string) $cp->action_result,
                'fp' => $this->fingerprint((string) $cp->action_taken, (string) $cp->action_result),
            ]
        )->values()->all();

        // Check for identical consecutive action+output pairs.
        $sameFp = $items[0]['fp'];
        for ($i = 1; $i < count($items); $i++) {
            if ($items[$i]['fp'] !== $sameFp) {
                $sameFp = null;
                break;
            }
        }

        if ($sameFp !== null) {
            return "Repeated identical action '{$items[0]['action']}' with the same result {$threshold} times.";
        }

        // Check for alternating inspect/modify of the same file path.
        $paths = array_map(fn (array $item): ?string => $this->extractPath($item['action']), $items);

        if ($this->hasAlternatingModification($items, $paths)) {
            return 'Alternating inspect/modify cycle detected on the same file.';
        }

        // Check for repeated failure of the same tool.
        $toolFails = array_filter($items, fn (array $item): bool => $this->isFailureResult($item['result']));

        if (count($toolFails) >= $threshold && $this->sameToolPattern($toolFails)) {
            return 'Same tool failing repeatedly with no progress.';
        }

        return null;
    }

    /**
     * @param  array<int, array<string, string>>  $items
     * @param  array<int, ?string>  $paths
     */
    private function hasAlternatingModification(array $items, array $paths): bool
    {
        $uniquePaths = array_values(array_filter(array_unique($paths), fn (?string $p): bool => $p !== null));

        if (count($uniquePaths) !== 1) {
            return false;
        }

        // At least 2 inspect + 2 write actions on the single path.
        $reads = 0;
        $writes = 0;

        foreach ($items as $item) {
            if ($this->isReadAction($item['action'])) {
                $reads++;
            } elseif ($this->isWriteAction($item['action'])) {
                $writes++;
            }
        }

        return $reads >= 2 && $writes >= 2;
    }

    private function isReadAction(string $action): bool
    {
        return str_contains($action, 'read') || str_contains($action, 'inspect') || str_contains($action, 'view');
    }

    private function isWriteAction(string $action): bool
    {
        return str_contains($action, 'write') || str_contains($action, 'edit') || str_contains($action, 'update') || str_contains($action, 'modify');
    }

    private function extractPath(string $action): ?string
    {
        if (preg_match('#[/\\\\][A-Za-z0-9_.\-/\\\\]+\.(?:php|js|ts|jsx|tsx|vue|py|rb|go|rs|java|kt|swift|c|cpp|h|hpp)\b#i', $action, $m)) {
            return $m[0];
        }

        return null;
    }

    private function isFailureResult(string $result): bool
    {
        $lower = strtolower($result);

        foreach (self::FAILURE_SIGNALS as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function sameToolPattern(array $items): bool
    {
        $toolNames = [];

        foreach ($items as $item) {
            $tool = strtok($item['action'], ' ');
            if ($tool !== false) {
                $toolNames[] = $tool;
            }
        }

        return count(array_unique($toolNames)) <= 1;
    }
}
