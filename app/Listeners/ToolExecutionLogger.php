<?php

namespace App\Listeners;

use App\Events\ToolExecutionCompleted;
use App\Events\ToolExecutionFailed;
use App\Events\ToolExecutionStarted;
use App\Models\Event;

/**
 * Persists per-tool execution events to the events table for observability.
 */
class ToolExecutionLogger
{
    public function handleToolExecutionStarted(ToolExecutionStarted $event): void
    {
        $this->log(
            $event->toolName,
            $event->task->getKey(),
            'tool.started',
            'Tool started',
            "Executing tool: {$event->toolName}",
            'info',
            ['tool_call_id' => $event->toolCallId],
        );
    }

    public function handleToolExecutionCompleted(ToolExecutionCompleted $event): void
    {
        $this->log(
            $event->toolName,
            $event->task->getKey(),
            'tool.completed',
            'Tool completed',
            "Tool {$event->toolName} completed in {$event->durationMs}ms.",
            'success',
            [
                'tool_call_id' => $event->toolCallId,
                'output' => substr($event->output, 0, 500),
                'duration_ms' => $event->durationMs,
            ],
        );
    }

    public function handleToolExecutionFailed(ToolExecutionFailed $event): void
    {
        $this->log(
            $event->toolName,
            $event->task->getKey(),
            'tool.failed',
            'Tool failed',
            "Tool {$event->toolName} failed: ".substr($event->error, 0, 500),
            'error',
            [
                'tool_call_id' => $event->toolCallId,
                'error' => substr($event->error, 0, 500),
            ],
        );
    }

    private function log(
        string $toolName,
        int|string|null $taskId,
        string $eventType,
        string $title,
        string $message,
        string $level,
        array $data = [],
    ): void {
        try {
            Event::query()->create([
                'event_type' => $eventType,
                'entity_type' => 'task',
                'entity_id' => $taskId !== null ? (string) $taskId : null,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'level' => $level,
                'metadata' => [
                    'source' => 'task-engine',
                    'tool' => $toolName,
                ],
            ]);
        } catch (\Throwable) {
            // Logging must never break tool execution.
        }
    }
}
