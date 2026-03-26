<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MetricSnapshot;
use App\Models\TaskLog;
use App\Services\Agent\OllamaService;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __construct(
        protected OllamaService $ollama,
    ) {}

    public function index(): JsonResponse
    {
        $snapshots = MetricSnapshot::query()->orderBy('recorded_at')->get();
        $tasks = TaskLog::query()->get();

        $tokensOverTime = $snapshots
            ->groupBy(fn (MetricSnapshot $snapshot): string => $snapshot->recorded_at?->format('Y-m-d H:00') ?? 'unknown')
            ->map(fn ($group, string $hour): array => [
                'hour' => $hour,
                'tokens' => (int) $group->sum('completion_tokens'),
            ])
            ->values()
            ->all();

        $latencyOverTime = $snapshots
            ->groupBy(fn (MetricSnapshot $snapshot): string => $snapshot->recorded_at?->format('Y-m-d H:00') ?? 'unknown')
            ->map(fn ($group, string $hour): array => [
                'hour' => $hour,
                'avg_ms' => (int) round($group->avg('total_duration_ms') ?? 0),
            ])
            ->values()
            ->all();

        $toolUsage = $tasks
            ->groupBy('tool_name')
            ->map(fn ($group, $toolName): array => [
                'tool_name' => (string) $toolName,
                'count' => $group->count(),
                'avg_ms' => (int) round($group->avg('duration_ms') ?? 0),
                'errors' => $group->where('status', 'error')->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $totalTasks = $tasks->count();
        $errorCount = $tasks->where('status', 'error')->count();

        return response()->json([
            'ollama_health' => $this->ollama->healthCheck(),
            'avg_tokens_per_sec' => round($snapshots->avg('tokens_per_second') ?? 0, 2),
            'avg_latency_ms' => (int) round($snapshots->avg('total_duration_ms') ?? 0),
            'total_tokens' => (int) $snapshots->sum(fn (MetricSnapshot $snapshot): int => $snapshot->prompt_tokens + $snapshot->completion_tokens),
            'total_conversations' => Conversation::query()->count(),
            'total_messages' => Message::query()->count(),
            'total_tasks' => $totalTasks,
            'task_error_rate' => $totalTasks > 0 ? round(($errorCount / $totalTasks) * 100, 2) : 0,
            'tokens_over_time' => $tokensOverTime,
            'latency_over_time' => $latencyOverTime,
            'tool_usage' => $toolUsage,
        ]);
    }
}
