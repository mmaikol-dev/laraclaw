<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetricSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'tokens_per_second',
        'prompt_tokens',
        'completion_tokens',
        'total_duration_ms',
        'load_duration_ms',
        'model',
        'tool_name',
        'extra',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'extra' => 'array',
        ];
    }

    /**
     * @param  array{
     *     tokens_per_second?: float|int,
     *     prompt_tokens?: int,
     *     completion_tokens?: int,
     *     total_duration_ms?: int,
     *     load_duration_ms?: int,
     *     extra?: array<mixed>
     * }  $stats
     */
    public static function record(array $stats, string $model, ?string $toolName = null): self
    {
        return self::query()->create([
            'tokens_per_second' => (float) ($stats['tokens_per_second'] ?? 0),
            'prompt_tokens' => (int) ($stats['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($stats['completion_tokens'] ?? 0),
            'total_duration_ms' => (int) ($stats['total_duration_ms'] ?? 0),
            'load_duration_ms' => (int) ($stats['load_duration_ms'] ?? 0),
            'model' => $model,
            'tool_name' => $toolName,
            'extra' => $stats['extra'] ?? null,
            'recorded_at' => now(),
        ]);
    }
}
