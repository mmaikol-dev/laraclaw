<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCheckpoint extends Model
{
    protected $fillable = [
        'task_id',
        'step_sort_order',
        'action_taken',
        'action_result',
        'task_status',
        'current_step',
        'execution_state',
        'summary',
        'tool_calls_summary',
    ];

    protected function casts(): array
    {
        return [
            'execution_state' => 'array',
            'tool_calls_summary' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
