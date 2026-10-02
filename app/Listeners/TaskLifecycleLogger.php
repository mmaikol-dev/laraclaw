<?php

namespace App\Listeners;

use App\Events\TaskCancelled;
use App\Events\TaskCheckpointCreated;
use App\Events\TaskCompleted;
use App\Events\TaskCreated;
use App\Events\TaskEscalated;
use App\Events\TaskFailed;
use App\Events\TaskPlanned;
use App\Events\TaskRetrying;
use App\Events\TaskStarted;
use App\Events\TaskStepStarted;
use App\Events\TaskVerificationFailed;
use App\Events\TaskVerificationPassed;
use App\Events\TaskVerificationStarted;
use App\Models\Event;
use App\Models\Task;

/**
 * Persists task lifecycle events to the events table so that long-running task
 * history is observable without depending on the model context.
 */
class TaskLifecycleLogger
{
    public function handleTaskCreated(TaskCreated $event): void
    {
        $this->log(
            $event->task,
            'task.created',
            'Task created',
            "Task created: {$event->task->goal}",
            'info',
        );
    }

    public function handleTaskPlanningStarted(TaskPlanningStarted $event): void
    {
        $this->log(
            $event->task,
            'task.planning_started',
            'Planning started',
            'The planner is decomposing the goal into steps.',
            'info',
        );
    }

    public function handleTaskPlanned(TaskPlanned $event): void
    {
        $this->log(
            $event->task,
            'task.planned',
            'Task planned',
            "Plan generated with {$event->task->total_steps} step(s).",
            'info',
        );
    }

    public function handleTaskStarted(TaskStarted $event): void
    {
        $this->log(
            $event->task,
            'task.started',
            'Task started',
            'Execution began.',
            'info',
        );
    }

    public function handleTaskStepStarted(TaskStepStarted $event): void
    {
        $step = $event->task->steps()->where('sort_order', $event->stepSortOrder)->first();

        $this->log(
            $event->task,
            'task.step_started',
            "Step {$event->stepSortOrder} started",
            $step?->description ?? "Starting step {$event->stepSortOrder}",
            'info',
        );
    }

    public function handleTaskCheckpointCreated(TaskCheckpointCreated $event): void
    {
        $this->log(
            $event->task,
            'task.checkpoint',
            'Checkpoint recorded',
            "Checkpoint at step {$event->task->current_step}.",
            'info',
        );
    }

    public function handleTaskRetrying(TaskRetrying $event): void
    {
        $this->log(
            $event->task,
            'task.retrying',
            'Task retrying',
            "Attempt {$event->attempt}: ".($event->reason ?? 'Retrying after failure.'),
            'warning',
        );
    }

    public function handleTaskEscalated(TaskEscalated $event): void
    {
        $this->log(
            $event->task,
            'task.escalated',
            'Model escalated',
            "Model escalated from {$event->fromModel} to {$event->toModel}: {$event->reason}",
            'warning',
        );
    }

    public function handleTaskVerificationStarted(TaskVerificationStarted $event): void
    {
        $this->log(
            $event->task,
            'task.verification_started',
            'Verification started',
            'Independent verification of the task outcome began.',
            'info',
        );
    }

    public function handleTaskVerificationPassed(TaskVerificationPassed $event): void
    {
        $this->log(
            $event->task,
            'task.verification_passed',
            'Verification passed',
            'All acceptance criteria were satisfied.',
            'success',
        );
    }

    public function handleTaskVerificationFailed(TaskVerificationFailed $event): void
    {
        $this->log(
            $event->task,
            'task.verification_failed',
            'Verification failed',
            'Failed checks: '.implode(', ', $event->failedChecks),
            'error',
        );
    }

    public function handleTaskCompleted(TaskCompleted $event): void
    {
        $this->log(
            $event->task,
            'task.completed',
            'Task completed',
            'Task successfully completed after verification.',
            'success',
        );
    }

    public function handleTaskFailed(TaskFailed $event): void
    {
        $this->log(
            $event->task,
            'task.failed',
            'Task failed',
            $event->reason,
            'error',
        );
    }

    public function handleTaskCancelled(TaskCancelled $event): void
    {
        $this->log(
            $event->task,
            'task.cancelled',
            'Task cancelled',
            'Task was cancelled by the user or supervisor.',
            'warning',
        );
    }

    private function log(
        Task $task,
        string $eventType,
        string $title,
        string $message,
        string $level,
    ): void {
        try {
            Event::query()->create([
                'event_type' => $eventType,
                'entity_type' => 'task',
                'entity_id' => (string) $task->getKey(),
                'title' => $title,
                'message' => $message,
                'data' => [
                    'task_id' => (int) $task->getKey(),
                    'status' => $task->status->value,
                    'current_step' => $task->current_step,
                    'total_steps' => $task->total_steps,
                ],
                'level' => $level,
                'metadata' => [
                    'source' => 'task-engine',
                    'user_id' => $task->user_id,
                ],
            ]);
        } catch (\Throwable) {
            // Logging must never break task execution.
        }
    }
}
