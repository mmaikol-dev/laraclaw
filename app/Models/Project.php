<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'name', 'description', 'goal', 'status',
        'due_date', 'conversation_id', 'progress_notes',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sort_order');
    }

    public function progressSummary(): string
    {
        $total = $this->tasks()->count();
        if ($total === 0) {
            return 'No tasks defined yet.';
        }
        $done = $this->tasks()->where('status', 'done')->count();
        $inProgress = $this->tasks()->where('status', 'in_progress')->count();
        $blocked = $this->tasks()->where('status', 'blocked')->count();

        return "{$done}/{$total} tasks done, {$inProgress} in progress, {$blocked} blocked.";
    }
}
