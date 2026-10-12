<?php

namespace App\Models;

use Database\Factories\AgentMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed agent-to-agent message. The office surface uses these to decide
 * who is huddling (kickoff/discussion) versus heads-down at a desk
 * (assignment).
 */
class AgentMessage extends Model
{
    /** @use HasFactory<AgentMessageFactory> */
    use HasFactory;

    public const KINDS = ['kickoff', 'discussion', 'assignment', 'status'];

    public const FROM_ORCHESTRATOR = 'orchestrator';

    protected $fillable = [
        'thread_key',
        'mission_id',
        'from_agent',
        'to_agent',
        'kind',
        'body',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function scopeRecent(Builder $query, int $minutes): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
}
