<?php

namespace App\Services\TaskEngine;

use App\Models\Task;

/**
 * Immutable result of a task engine execution.
 */
class TaskResult
{
    public function __construct(
        public readonly Task $task,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly ?array $verification = null,
    ) {}

    public static function fromTask(Task $task): self
    {
        return new self(
            task: $task,
            status: $task->status->value,
            error: $task->failure_reason,
            verification: $task->verification_results,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'completed';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->task->id,
            'status' => $this->status,
            'error' => $this->error,
            'verification' => $this->verification,
            'current_step' => $this->task->current_step,
            'total_steps' => $this->task->total_steps,
            'attempts' => $this->task->attempts,
        ];
    }
}
