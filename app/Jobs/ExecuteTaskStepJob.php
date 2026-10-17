<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Events\TaskStarted;
use App\Models\Task;
use App\Services\TaskEngine\TaskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Executes exactly ONE step (model turn) of a task, then automatically
 * dispatches the next step job until the task reaches a terminal state.
 *
 * This is the primitive that makes long-running tasks continue without the
 * user typing "continue". A model response ending is treated as one step, not
 * as the end of the task.
 *
 * The job is idempotent: it re-reads task state from the database, repositions
 * itself via the latest checkpoint, and acquires a lock so duplicate workers
 * never execute the same step twice.
 */
class ExecuteTaskStepJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $taskId)
    {
        // Match the worker timeout to the longest a single agent step may run,
        // otherwise a long (but healthy) step is killed mid-flight, the step is
        // left Running and the task never continues.
        $this->timeout = (int) config('agent.task.step_timeout_seconds', 3600);
    }

    public function handle(TaskEngine $engine): void
    {
        $task = Task::query()->find($this->taskId);

        if ($task === null || $task->status->isTerminal()) {
            return;
        }

        // Paused is a deliberate user hold; Verifying is owned by FinalizeTaskJob.
        if (in_array($task->status, [TaskStatus::Paused, TaskStatus::Verifying], true)) {
            return;
        }

        // The lock must outlive a full step, or it could expire while the step is
        // still running and let a second worker execute the same task.
        $lockTtl = $this->timeout + 60;
        $lock = Cache::lock('task-execute:'.$task->id, $lockTtl);

        if (! $lock->get()) {
            // Another worker already owns this task and will dispatch the
            // continuation itself. Dropping here (rather than re-dispatching)
            // guarantees a step is never executed twice; a worker that died
            // holding the lock is reclaimed by the supervisor's stale recovery.
            return;
        }

        $shouldFinalize = false;

        try {
            // A fresh task with no plan yet gets one before its first step.
            if ($task->status === TaskStatus::Pending && $task->steps()->count() === 0) {
                $engine->plan($task);
                $task->refresh();
            }

            // Waiting tasks only resume when their condition is satisfied.
            if ($task->status === TaskStatus::Waiting) {
                if (! $engine->resumeConditionMet($task)) {
                    return;
                }

                $engine->resumeFromWaiting($task);
                $task->refresh();
            }

            if (in_array($task->status, [TaskStatus::Pending, TaskStatus::Planning], true)) {
                $task->markRunning();
                event(new TaskStarted($task));
                $task->refresh();
            }

            $engine->executeNextStep($task);
            $task->refresh();

            $shouldFinalize = ! $task->status->isTerminal() && ! $task->hasUnfinishedSteps();
        } finally {
            $lock->release();
        }

        if ($task->status->isTerminal()) {
            return;
        }

        // Continuation is dispatched AFTER the lock is released so the follow-up
        // job can re-acquire it on any queue connection.
        $queue = (string) config('agent.task.queue', 'tasks');

        if ($shouldFinalize) {
            FinalizeTaskJob::dispatch($task->id)->onQueue($queue);
        } else {
            self::dispatch($task->id)->onQueue($queue);
        }
    }
}
