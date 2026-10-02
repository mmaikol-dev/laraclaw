<?php

namespace App\Services\TaskEngine;

use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;

/**
 * Supervisor / watchdog for long-running tasks.
 *
 * Inspects active tasks periodically (via the scheduled job), detects stale
 * tasks (missing heartbeats), excessive retries, and stuck steps, then marks
 * them for recovery or escalation. Recovered tasks are always re-queued so they
 * continue automatically — never left silently `running`.
 */
class TaskSupervisor
{
    public function __construct(
        protected TaskEngine $engine,
    ) {}

    /**
     * Inspect all active tasks and take corrective action.
     *
     * @return array<int, array<string, mixed>> actions taken, for observability/tests
     */
    public function inspect(): array
    {
        $actions = [];

        // 1. Stale tasks (no heartbeat for over the grace period + interval).
        //    Paused tasks are excluded — a pause is a deliberate user hold.
        $grace = (int) config('agent.task.stale_grace_seconds', 120);
        $stale = Task::query()
            ->active()
            ->where('status', '!=', TaskStatus::Paused)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds($grace))
            ->get()
            ->filter(fn (Task $task): bool => $task->status !== TaskStatus::Waiting || $this->engine->resumeConditionMet($task));

        foreach ($stale as $task) {
            $actions[] = $this->recoverStale($task);
        }

        // 2. Tasks with excessive attempts (should be escalated or failed by engine, but guard here)
        $max = (int) config('agent.task.max_attempts', 5);

        Task::query()
            ->whereIn('status', [TaskStatus::Retrying, TaskStatus::Running])
            ->where('attempts', '>=', $max)
            ->get()
            ->each(function (Task $task) use (&$actions, $max): void {
                $task->markFailed("Exceeded maximum attempts ({$max}). Supervisor intervened.", TaskFailureType::Unrecoverable);
                $actions[] = [
                    'task_id' => $task->id,
                    'action' => 'failed',
                    'reason' => 'excessive attempts',
                ];
            });

        // 3. Tasks stuck on the same step for too long (no progress, no new heartbeat)
        $this->detectStuckSteps($actions);

        // 4. Waiting tasks whose resume condition is now satisfied — resume
        //    them automatically and re-queue execution.
        $actions = [...$actions, ...$this->resumeReadyWaitingTasks()];

        return $actions;
    }

    /**
     * Recover a single stale task and re-queue it for automatic continuation.
     *
     * @return array<string, mixed>
     */
    public function recoverStale(Task $task): array
    {
        $recovery = $this->engine->recover($task);

        ExecuteTaskStepJob::dispatch($task->id);

        return array_merge($recovery, [
            'task_id' => $task->id,
            'action' => 'recovered',
            'reason' => 'stale ('.($task->last_heartbeat_at?->diffForHumans()).')',
        ]);
    }

    /**
     * Auto-resume tasks that were waiting for a time-based condition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resumeReadyWaitingTasks(): array
    {
        $actions = [];

        Task::query()
            ->where('status', TaskStatus::Waiting)
            ->whereNotNull('waiting_since')
            ->get()
            ->each(function (Task $task) use (&$actions): void {
                if (! $this->engine->resumeConditionMet($task)) {
                    return;
                }

                $this->engine->resumeFromWaiting($task);
                ExecuteTaskStepJob::dispatch($task->id);
                $actions[] = [
                    'task_id' => $task->id,
                    'action' => 'resumed',
                    'reason' => 'waiting condition satisfied',
                ];
            });

        return $actions;
    }

    /**
     * Detect tasks whose current step hasn't changed and that haven't
     * heartbeated in a long time (hard stuck).
     *
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function detectStuckSteps(array &$actions): void
    {
        $maxMinutes = (int) config('agent.task.max_execution_minutes', 60);

        Task::query()
            ->whereIn('status', [TaskStatus::Running, TaskStatus::Retrying])
            ->whereNotNull('started_at')
            ->where('started_at', '<', now()->subMinutes($maxMinutes))
            ->get()
            ->each(function (Task $task) use (&$actions, $maxMinutes): void {
                $task->markFailed("Task exceeded maximum execution time of {$maxMinutes} minutes.", TaskFailureType::Transient);
                $actions[] = [
                    'task_id' => $task->id,
                    'action' => 'failed',
                    'reason' => 'max execution time exceeded',
                ];
            });
    }
}
