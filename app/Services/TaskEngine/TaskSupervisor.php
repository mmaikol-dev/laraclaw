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

        // 1. In-flight heartbeat. A step that is still executing keeps its task
        //    alive for the duration of its timeout, so a legitimately long agent
        //    turn is never mistaken for a stalled task and spuriously recovered.
        $this->refreshInFlightHeartbeats();

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

        // 3. Stale tasks (no heartbeat for over the grace period + interval).
        //    Paused tasks are excluded — a pause is a deliberate user hold.
        //    Recovered tasks are re-queued and get a fresh execution window.
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

        // 4. Waiting tasks whose resume condition is now satisfied — resume
        //    them automatically and re-queue execution.
        $actions = [...$actions, ...$this->resumeReadyWaitingTasks()];

        return $actions;
    }

    /**
     * Recover a single stale task and re-queue it for automatic continuation.
     *
     * Recovery is bounded: once a task has been auto-recovered
     * `max_auto_recoveries` times in a row without making progress, it is parked
     * for human input instead of being restarted again. This stops the agent
     * from burning work on a task that is genuinely stuck.
     *
     * @return array<string, mixed>
     */
    public function recoverStale(Task $task): array
    {
        $max = (int) config('agent.task.max_auto_recoveries', 3);

        if ($task->auto_recoveries >= $max) {
            $this->engine->park(
                $task,
                "Task stalled {$task->auto_recoveries} times without making progress. Awaiting human input before restarting.",
                TaskFailureType::Unrecoverable,
            );

            return [
                'task_id' => $task->id,
                'action' => 'parked_needs_human',
                'reason' => 'exceeded max auto-recoveries ('.$max.')',
            ];
        }

        $recovery = $this->engine->recover($task, null, automatic: true);

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
     * Keep the heartbeat fresh for tasks whose current step is still executing
     * within its allowed window. This is the in-flight heartbeat: without it a
     * step that runs longer than the stale grace period would be flagged as
     * stalled and recovered while it is still making progress.
     */
    private function refreshInFlightHeartbeats(): void
    {
        Task::query()
            ->whereIn('status', [TaskStatus::Running, TaskStatus::Retrying])
            ->get()
            ->each(function (Task $task): void {
                if ($task->hasInFlightStep()) {
                    $task->touchHeartbeat();
                }
            });
    }
}
