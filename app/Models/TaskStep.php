<?php

namespace App\Models;

use App\Enums\TaskFailureType;
use App\Enums\TaskStepStatus;
use Database\Factories\TaskStepFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class TaskStep extends Model
{
    /** @use HasFactory<TaskStepFactory> */
    use HasFactory;

    protected $fillable = [
        'task_id',
        'sort_order',
        'status',
        'description',
        'prompt',
        'depends_on',
        'attempts',
        'max_attempts',
        'result',
        'error',
        'failure_type',
        'started_at',
        'completed_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'max_attempts' => 3,
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStepStatus::class,
            'failure_type' => TaskFailureType::class,
            'depends_on' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Validate and apply a state transition.
     */
    public function transitionTo(TaskStepStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new InvalidArgumentException(
                "Invalid step transition from [{$this->status->value}] to [{$newStatus->value}]."
            );
        }

        $this->update(['status' => $newStatus]);
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => TaskStepStatus::Running,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markCompleted(string $result): void
    {
        $this->update([
            'status' => TaskStepStatus::Completed,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $error, ?TaskFailureType $failureType = null): void
    {
        $this->update([
            'status' => TaskStepStatus::Failed,
            'error' => $error,
            'failure_type' => $failureType,
        ]);
    }

    public function markRetrying(): void
    {
        $this->increment('attempts');
        $this->transitionTo(TaskStepStatus::Retrying);
    }

    /**
     * Check if all dependencies are satisfied.
     */
    public function dependenciesMet(): bool
    {
        if ($this->depends_on === null || $this->depends_on === []) {
            return true;
        }

        $completedOrders = $this->task->steps()
            ->where('status', TaskStepStatus::Completed)
            ->pluck('sort_order')
            ->all();

        foreach ($this->depends_on as $dep) {
            if (! in_array($dep, $completedOrders, true)) {
                return false;
            }
        }

        return true;
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    public function isRetryableFailure(): bool
    {
        return $this->failure_type?->isRetryable() ?? false;
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TaskStepStatus::Pending);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [TaskStepStatus::Pending, TaskStepStatus::Running, TaskStepStatus::Retrying]);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TaskStepStatus::Completed);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', TaskStepStatus::Failed);
    }
}
