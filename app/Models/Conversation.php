<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'model',
        'role_profile_id',
        'identity_label',
        'active_goal',
        'completion_criteria',
        'verification_status',
        'verification_notes',
        'next_action',
        'resumable_state',
        'last_verified_at',
        'last_resumed_at',
        'system_prompt',
        'total_tokens',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'system_prompt' => 'array',
            'completion_criteria' => 'array',
            'resumable_state' => 'array',
            'is_archived' => 'boolean',
            'last_verified_at' => 'datetime',
            'last_resumed_at' => 'datetime',
        ];
    }

    /**
     * Every automated surface (task engine, missions, projects, scheduled tasks,
     * triggers, webhooks, reports) creates conversations without naming a model.
     * Resolving it here keeps a single, config-driven source of truth instead of
     * letting the conversations.model column default decide — that default was a
     * retired cloud model, which made every autonomous feature fail with HTTP 410.
     */
    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            if (trim((string) $conversation->model) === '') {
                $conversation->model = (string) config('ollama.agent_model');
            }
        });
    }

    public function roleProfile(): BelongsTo
    {
        return $this->belongsTo(AgentRoleProfile::class, 'role_profile_id');
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
