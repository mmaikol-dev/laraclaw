<?php

namespace App\Jobs;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\TaskEngine\TaskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the completion gate for a task: independent verification, and only then
 * a transition to `completed`. If verification fails, repair steps are added
 * and the next ExecuteTaskStepJob is dispatched automatically.
 */
class FinalizeTaskJob implements ShouldQueue
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

        $lock = Cache::lock('task-finalize:'.$task->id, 300);

        if (! $lock->get()) {
            return;
        }

        $repairNeeded = false;

        try {
            $result = $engine->finalizeTask($task);
            $repairNeeded = $result->task->status === TaskStatus::Running;
        } finally {
            $lock->release();
        }

        if ($repairNeeded) {
            ExecuteTaskStepJob::dispatch($task->id)->onQueue((string) config('agent.task.queue', 'tasks'));
        }
    }
}
