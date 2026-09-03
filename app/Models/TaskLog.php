<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'message_id',
        'tool_name',
        'tool_input',
        'tool_output',
        'status',
        'error_message',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'tool_input' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => 'running',
            'error_message' => null,
        ])->save();
    }

    public function markSuccess(string $output, int $durationMs): void
    {
        $this->forceFill([
            'status' => 'success',
            'tool_output' => $output,
            'error_message' => null,
            'duration_ms' => $durationMs,
        ])->save();
    }

    public function markError(string $error, int $durationMs): void
    {
        $this->forceFill([
            'status' => 'error',
            'error_message' => $error,
            'duration_ms' => $durationMs,
        ])->save();
    }
}
