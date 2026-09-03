<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'thinking',
        'tool_calls',
        'tool_result',
        'tool_name',
        'prompt_tokens',
        'completion_tokens',
        'tokens_per_second',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_result' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function taskLogs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    /**
     * @return array{role: string, content: ?string, tool_calls?: array<int, mixed>}
     */
    public function toOllamaFormat(): array
    {
        $message = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->role === 'assistant' && ! empty($this->tool_calls)) {
            $message['tool_calls'] = $this->tool_calls;
        }

        return $message;
    }
}
