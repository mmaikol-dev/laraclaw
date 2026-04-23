<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Event extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'event_type',
        'entity_type',
        'entity_id',
        'title',
        'message',
        'data',
        'level',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
        'data' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Get the trigger that owns this event.
     */
    public function trigger(): ?BelongsTo
    {
        return $this->belongsTo(Trigger::class, 'entity_id', 'id');
    }

    /**
     * Get the scheduled task that owns this event.
     */
    public function scheduledTask(): ?BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class, 'entity_id', 'id');
    }

    /**
     * Get the project that owns this event.
     */
    public function project(): ?BelongsTo
    {
        return $this->belongsTo(Project::class, 'entity_id', 'id');
    }

    /**
     * Generate a UUID for the event.
     */
    public static function generateUuid(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Scope to filter by event type.
     */
    public function scopeEventType($query, string $type): $query
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope to filter by entity type.
     */
    public function scopeEntityType($query, string $type): $query
    {
        return $query->where('entity_type', $type);
    }

    /**
     * Scope to filter by level.
     */
    public function scopeLevel($query, string $level): $query
    {
        return $query->where('level', $level);
    }
}
