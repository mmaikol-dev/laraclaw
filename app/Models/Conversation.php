<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'model',
        'system_prompt',
        'total_tokens',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'system_prompt' => 'array',
            'is_archived' => 'boolean',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function taskLogs(): HasMany
    {
        return $this->hasMany(TaskLog::class)->latest();
    }

    /**
     * @return array<int, array{role: string, content: ?string, tool_calls?: array<int, mixed>}>
     */
    public function toOllamaMessages(): array
    {
        /** @var Collection<int, Message> $messages */
        $messages = $this->relationLoaded('messages') ? $this->messages : $this->messages()->get();

        return $messages
            ->filter(fn (Message $message): bool => in_array($message->role, ['user', 'assistant', 'tool'], true))
            ->map(fn (Message $message): array => $message->toOllamaFormat())
            ->values()
            ->all();
    }
}
