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

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $taskId) {}

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

        $lock = Cache::lock('task-execute:'.$task->id, 300);

        if (! $lock->get()) {
            return; // another worker is already executing this task
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
