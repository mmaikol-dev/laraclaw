<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaskLog;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    public function index(): JsonResponse
    {
        $tasks = TaskLog::query()
            ->with('conversation:id,title')
            ->when(request('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when(request('tool'), fn ($query, string $tool) => $query->where('tool_name', $tool))
            ->when(request('conversation_id'), fn ($query, string $conversationId) => $query->where('conversation_id', $conversationId))
            ->latest()
            ->paginate(50)
            ->through(fn (TaskLog $task): array => [
                'id' => $task->id,
                'tool_name' => $task->tool_name,
                'tool_input' => $task->tool_input,
                'tool_output' => $task->tool_output,
                'status' => $task->status,
                'error_message' => $task->error_message,
                'duration_ms' => $task->duration_ms,
                'conversation' => $task->conversation ? [
                    'id' => $task->conversation->id,
                    'title' => $task->conversation->title,
                ] : null,
                'created_at' => $task->created_at?->toISOString(),
            ]);

        return response()->json($tasks);
    }

    public function show(TaskLog $task): JsonResponse
    {
        $task->load('conversation:id,title');

        return response()->json([
            'data' => [
                'id' => $task->id,
                'tool_name' => $task->tool_name,
                'tool_input' => $task->tool_input,
                'tool_output' => $task->tool_output,
                'status' => $task->status,
                'error_message' => $task->error_message,
                'duration_ms' => $task->duration_ms,
                'conversation' => $task->conversation ? [
                    'id' => $task->conversation->id,
                    'title' => $task->conversation->title,
                ] : null,
                'created_at' => $task->created_at?->toISOString(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $tasks = TaskLog::query()->get();
        $total = $tasks->count();
        $success = $tasks->where('status', 'success')->count();
        $error = $tasks->where('status', 'error')->count();
        $running = $tasks->where('status', 'running')->count();
        $today = $tasks->filter(fn (TaskLog $task): bool => $task->created_at?->isToday() ?? false)->count();

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

        return response()->json([
            'total' => $total,
            'success' => $success,
            'error' => $error,
            'running' => $running,
            'today' => $today,
            'breakdown' => $toolUsage,
        ]);
    }
}
