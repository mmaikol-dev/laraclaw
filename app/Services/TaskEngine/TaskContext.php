<?php

namespace App\Services\TaskEngine;

use App\Models\Task;
use App\Models\TaskCheckpoint;

/**
 * Compiles a compact, persistent-source-of-truth context summary for a task.
 *
 * Instead of feeding an unbounded conversation history to the model, each step
 * is dispatched with a tight summary of durable task state: goal, progress,
 * the last checkpoint (what was just done), the last error, and the outcomes of
 * completed steps. All data comes from the database — never from the model's
 * prior claims.
 */
class TaskContext
{
    /**
     * Build a compact execution summary for a task.
     */
    public function buildSummary(Task $task): string
    {
        $lines = [];
        $lines[] = 'Task: '.$task->goal;
        $lines[] = 'Progress: step '.$task->current_step.' of '.$task->total_steps
            .' ('.number_format($task->progressPercentage(), 1).'%)';
        $lines[] = 'Status: '.$task->status->label();

        if ($task->complexity_level !== null) {
            $lines[] = 'Complexity: '.$task->complexity_level;
        }

        if ($task->attempts > 0) {
            $lines[] = "Retries used: {$task->attempts} / {$task->max_attempts}";
        }

        $checkpoint = $task->latestCheckpoint;
        if ($checkpoint !== null) {
            $lines[] = $this->checkpointSummary($checkpoint);
        }

        if (filled($task->last_error)) {
            $lines[] = 'Last error: '.str($task->last_error)->limit(300);
        }

        $completed = $task->steps()
            ->where('status', 'completed')
            ->orderBy('sort_order')
            ->get();

        if ($completed->isNotEmpty()) {
            $outcomes = $completed->map(fn ($s): string => '#'.($s->sort_order).' '.($s->description))->implode(' | ');
            $lines[] = 'Completed steps: '.$outcomes;
        }

        if (filled($task->acceptance_criteria)) {
            $lines[] = 'Acceptance criteria: '.implode(' | ', $task->acceptance_criteria);
        }

        return implode("\n", $lines);
    }

    /**
     * Build a one-line summary of the most recent checkpoint.
     */
    public function checkpointSummary(TaskCheckpoint $checkpoint): string
    {
        $result = trim((string) $checkpoint->action_result);

        return 'Last completed action: '.$checkpoint->action_taken
            .($result !== '' ? ' — '.str($result)->limit(160) : '');
    }

    /**
     * Human-readable reason explaining why the latest checkpoint happened.
     */
    public function buildCtxBlock(Task $task): string
    {
        return "\n\nCURRENT STATE SUMMARY\n{$this->buildSummary($task)}";
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Task $task): array
    {
        $checkpoint = $task->latestCheckpoint;

        return [
            'current_step' => $task->current_step,
            'total_steps' => $task->total_steps,
            'progress_percentage' => $task->progressPercentage(),
            'attempts' => $task->attempts,
            'verification_status' => $task->verification_status,
            'latest_checkpoint' => $checkpoint === null ? null : [
                'step_sort_order' => $checkpoint->step_sort_order,
                'action_taken' => $checkpoint->action_taken,
                'current_step' => $checkpoint->current_step,
            ],
            'last_error' => $task->last_error,
        ];
    }
}
