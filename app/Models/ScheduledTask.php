<?php

namespace App\Models;

use Carbon\Carbon;
use Cron\CronExpression;
use Database\Factories\ScheduledTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTask extends Model
{
    /** @use HasFactory<ScheduledTaskFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'cron_expression', 'prompt',
        'is_active', 'last_run_at', 'next_run_at',
        'conversation_id', 'use_same_conversation', 'last_result',
        'run_as_mission', 'mission_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'use_same_conversation' => 'boolean',
        'run_as_mission' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'last_result' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function isDue(): bool
    {
        try {
            return (new CronExpression($this->cron_expression))->isDue();
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateNextRun(): void
    {
        try {
            $next = Carbon::instance((new CronExpression($this->cron_expression))->getNextRunDate());
            $this->update(['last_run_at' => now(), 'next_run_at' => $next]);
        } catch (\Throwable) {
            $this->update(['last_run_at' => now()]);
        }
    }
}
