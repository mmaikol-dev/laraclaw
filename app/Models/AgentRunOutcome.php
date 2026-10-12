<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRunOutcome extends Model
{
    public const SOURCE_CHAT = 'chat';

    public const SOURCE_TASK = 'task';

    public const SOURCE_MISSION = 'mission';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * An outcome whose correctness was checked by an independent verifier.
     */
    public const VERDICT_PASSED = 'passed';

    public const VERDICT_FAILED = 'failed';

    /**
     * No verifier examined the run. Recorded outcomes are never assumed to be
     * correct merely because the agent claimed they were.
     */
    public const VERDICT_UNVERIFIED = 'unverified';

    protected $fillable = [
        'conversation_id', 'user_id', 'role_profile_id',
        'source', 'subject_type', 'subject_id',
        'intent', 'status', 'verdict', 'verdict_source',
        'model', 'failure_type', 'failure_reason', 'tools_used', 'summary',
    ];

    protected $casts = [
        'tools_used' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roleProfile(): BelongsTo
    {
        return $this->belongsTo(AgentRoleProfile::class);
    }

    public function subject(): ?Model
    {
        if ($this->subject_type === null || $this->subject_id === null) {
            return null;
        }

        return $this->subject_type::query()->find($this->subject_id);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereIn('verdict', [self::VERDICT_PASSED, self::VERDICT_FAILED]);
    }

    public function scopeUnverified(Builder $query): Builder
    {
        return $query->where('verdict', self::VERDICT_UNVERIFIED);
    }

    public function wasVerified(): bool
    {
        return $this->verdict !== self::VERDICT_UNVERIFIED;
    }
}
