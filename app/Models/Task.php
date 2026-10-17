<?php

namespace App\Models;

use App\Enums\SudoStatus;
use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'status',
        'goal',
        'plan',
        'current_step',
        'total_steps',
        'execution_state',
        'attempts',
        'max_attempts',
        'auto_recoveries',
        'needs_human_input',
        'failure_type',
        'failure_reason',
        'last_heartbeat_at',
        'heartbeat_interval_seconds',
        'last_action',
        'last_result',
        'last_error',
        'waiting_reason',
        'waiting_since',
        'resume_condition',
        'sudo_status',
        'sudo_reason',
        'sudo_requested_at',
        'sudo_resolved_at',
        'sudo_resolved_by',
        'verification_status',
        'acceptance_criteria',
        'verification_results',
        'last_verified_at',
        'model',
        'complexity_level',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'failure_type' => TaskFailureType::class,
            'sudo_status' => SudoStatus::class,
            'execution_state' => 'array',
            'acceptance_criteria' => 'array',
            'verification_results' => 'array',
            'needs_human_input' => 'boolean',
            'auto_recoveries' => 'integer',
            'last_heartbeat_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'waiting_since' => 'datetime',
            'resume_condition' => 'array',
            'sudo_requested_at' => 'datetime',
            'sudo_resolved_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
        'current_step' => 0,
        'total_steps' => 0,
        'max_attempts' => 5,
        'auto_recoveries' => 0,
        'needs_human_input' => false,
        'heartbeat_interval_seconds' => 90,
        'sudo_status' => 'none',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TaskStep::class)->orderBy('sort_order');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(TaskCheckpoint::class)->latest();
    }

    public function latestCheckpoint(): HasOne
    {
        return $this->hasOne(TaskCheckpoint::class)->latestOfMany();
    }

    // -------------------------------------------------------------------------
    // State machine
    // -------------------------------------------------------------------------

    public function transitionTo(TaskStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new InvalidArgumentException(
                "Invalid transition from [{$this->status->value}] to [{$newStatus->value}]."
            );
        }

        $this->update(['status' => $newStatus]);
    }

    public function markRunning(): void
    {
        if ($this->started_at === null) {
            $this->update(['started_at' => now(), 'status' => TaskStatus::Running]);
        } else {
            $this->update(['status' => TaskStatus::Running]);
        }

        $this->touchHeartbeat();
    }

    public function markPaused(): void
    {
        $this->transitionTo(TaskStatus::Paused);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => TaskStatus::Completed,
            'completed_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }

    public function markFailed(string $reason, ?TaskFailureType $failureType = null): void
    {
        $this->update([
            'status' => TaskStatus::Failed,
            'failure_reason' => $reason,
            'failure_type' => $failureType,
            'completed_at' => now(),
        ]);
    }

    public function markCancelled(): void
    {
        $this->transitionTo(TaskStatus::Cancelled);
    }

    public function markRetrying(): void
    {
        $this->increment('attempts');
        $this->transitionTo(TaskStatus::Retrying);
    }

    /**
     * Put the task into a cooperative waiting state (e.g. awaiting approval,
     * an external service, or a scheduled time). Execution will resume only
     * when the resume condition becomes satisfied (or manually resumed).
     *
     * @param  array{type: string, after_minutes?: int}|null  $condition
     */
    public function markWaiting(string $reason, ?array $condition = null): void
    {
        $this->update([
            'status' => TaskStatus::Waiting,
            'waiting_reason' => $reason,
            'waiting_since' => now(),
            'resume_condition' => $condition,
        ]);
        $this->touchHeartbeat();
    }

    public function clearWaiting(): void
    {
        $this->forceFill([
            'waiting_reason' => null,
            'waiting_since' => null,
            'resume_condition' => null,
            'needs_human_input' => false,
        ])->save();
    }

    /**
     * Record that the task has been restarted automatically (by the supervisor)
     * without making real progress. Reset whenever a step completes.
     */
    public function incrementAutoRecoveries(): void
    {
        $this->increment('auto_recoveries');
    }

    public function resetAutoRecoveries(): void
    {
        if ($this->auto_recoveries !== 0) {
            $this->forceFill(['auto_recoveries' => 0])->save();
        }
    }

    // -------------------------------------------------------------------------
    // Elevated (sudo) approval
    // -------------------------------------------------------------------------

    public function sudoAllowed(): bool
    {
        return $this->sudo_status === SudoStatus::Approved;
    }

    public function requestSudo(string $reason): void
    {
        $this->forceFill([
            'sudo_status' => SudoStatus::Requested,
            'sudo_reason' => $reason,
            'sudo_requested_at' => now(),
            'sudo_resolved_at' => null,
            'sudo_resolved_by' => null,
        ])->save();
    }

    public function approveSudo(?int $userId = null): void
    {
        $this->forceFill([
            'sudo_status' => SudoStatus::Approved,
            'sudo_resolved_at' => now(),
            'sudo_resolved_by' => $userId,
        ])->save();
    }

    public function denySudo(?int $userId = null): void
    {
        $this->forceFill([
            'sudo_status' => SudoStatus::Denied,
            'sudo_resolved_at' => now(),
            'sudo_resolved_by' => $userId,
        ])->save();
    }

    // -------------------------------------------------------------------------
    // Heartbeat
    // -------------------------------------------------------------------------

    public function touchHeartbeat(): void
    {
        $this->update(['last_heartbeat_at' => now()]);
    }

    public function isStale(): bool
    {
        if ($this->last_heartbeat_at === null) {
            return false;
        }

        return $this->last_heartbeat_at
            ->addSeconds($this->heartbeat_interval_seconds)
            ->isPast();
    }

    /**
     * Whether a step is currently executing within its allowed time window.
     *
     * Acts as an in-flight heartbeat: a worker that is actively running a step
     * (e.g. a slow OpenCode turn) keeps the task alive for up to
     * `agent.task.step_timeout_seconds`, so it is never mistaken for a stalled
     * task. Once that window passes with the step still Running, the task is
     * considered dead and normal recovery applies.
     */
    public function hasInFlightStep(): bool
    {
        $timeout = (int) config('agent.task.step_timeout_seconds', 3600);

        return $this->steps()
            ->where('status', TaskStepStatus::Running)
            ->where('started_at', '>=', now()->subSeconds($timeout))
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Progress
    // -------------------------------------------------------------------------

    public function advanceStep(): void
    {
        $this->increment('current_step');
        $this->touchHeartbeat();
    }

    public function progressPercentage(): float
    {
        if ($this->total_steps === 0) {
            return 0.0;
        }

        return round(($this->current_step / $this->total_steps) * 100, 1);
    }

    public function currentStep(): ?TaskStep
    {
        return $this->steps()
            ->where('sort_order', $this->current_step)
            ->first();
    }

    public function nextPendingStep(): ?TaskStep
    {
        return $this->steps()
            ->where('status', TaskStepStatus::Pending)
            ->orderBy('sort_order')
            ->first();
    }

    public function hasUnfinishedSteps(): bool
    {
        return $this->steps()
            ->whereIn('status', [TaskStepStatus::Pending, TaskStepStatus::Running, TaskStepStatus::Retrying])
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Checkpointing
    // -------------------------------------------------------------------------

    public function createCheckpoint(
        string $actionTaken,
        ?string $actionResult = null,
        ?string $summary = null,
        ?array $toolCallsSummary = null,
        ?int $stepSortOrder = null,
    ): TaskCheckpoint {
        return $this->checkpoints()->create([
            'action_taken' => $actionTaken,
            'action_result' => $actionResult,
            'task_status' => $this->status->value,
            'current_step' => $this->current_step,
            'execution_state' => $this->execution_state,
            'step_sort_order' => $stepSortOrder,
            'summary' => $summary,
            'tool_calls_summary' => $toolCallsSummary,
        ]);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TaskStatus::Planning,
            TaskStatus::Running,
            TaskStatus::Paused,
            TaskStatus::Waiting,
            TaskStatus::Retrying,
            TaskStatus::Verifying,
        ]);
    }

    public function scopeStale(Builder $query, int $graceSeconds = 120): Builder
    {
        return $query->active()
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds($graceSeconds));
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function updateExecutionState(array $state): void
    {
        $current = $this->execution_state ?? [];
        $this->update(['execution_state' => array_merge($current, $state)]);
    }

    public function appendAcceptanceCriteria(array $criteria): void
    {
        $current = $this->acceptance_criteria ?? [];
        $this->update(['acceptance_criteria' => array_merge($current, $criteria)]);
    }
}
