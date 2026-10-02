<?php

namespace App\Services\Tools;

use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\TaskEngine\TaskEngine;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Lets the agent hand off long-running, multi-step goals to the TaskEngine so
 * they run autonomously (auto-continuing via the queue) with steps, durable
 * checkpoints, and verification — instead of the agent merely promising work
 * it will never get to once the current response ends.
 */
class TaskEngineTool extends BaseTool
{
    /**
     * The engine is resolved lazily on first use, never injected eagerly.
     *
     * ToolRegistry builds this tool while it is itself being constructed, and
     * TaskEngine depends on both ToolRegistry and AgentService. Eager injection
     * therefore forms a cycle (ToolRegistry -> TaskEngine -> AgentService ->
     * ToolRegistry -> ...) that recurses until the container exhausts memory.
     */
    public function __construct(private readonly Container $container) {}

    public function getName(): string
    {
        return 'task_engine';
    }

    public function getDescription(): string
    {
        return implode(' ', [
            'Create and drive tracked agent tasks that KEEP RUNNING after the current response ends — with a step plan, durable checkpoints, automatic continuation, and verification.',
            'Use for any goal the user wants carried to completion as one long-running job where it would be wrong to stop at a readable summary',
            '(e.g. "research the job market and apply to matching roles", "build a landing page", "migrate the database", "run a full QA pass").',
            'Do NOT describe such work or promise to do it later — create a task so it actually executes, and only report progress backed by task_engine status results.',
            'Quick single-turn questions and simple actions stay regular conversation, not tasks.',
        ]);
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'execute', 'status', 'list'],
                    'description' => implode(' ', [
                        'create: create a task (plan is generated, execution dispatched immediately).',
                        'execute: force-enqueue an existing task now.',
                        'status: read the REAL persisted progress of a task.',
                        'list: show the most recent tasks and their states.',
                    ]),
                ],
                'goal' => ['type' => 'string', 'description' => 'The concrete goal of the task.'],
                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional objective checks used for verification.'],
                'conversation_id' => ['type' => 'integer', 'description' => 'Optional conversation id to stream step work into. Omit to give the task its own private conversation.'],
                'task_id' => ['type' => 'integer', 'description' => 'Task id for execute/status.'],
                'limit' => ['type' => 'integer', 'description' => 'For list: how many recent tasks to show (default 10).'],
            ],
            'required' => ['action'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'create' => $this->create($arguments),
            'execute' => $this->enqueue((int) ($arguments['task_id'] ?? 0)),
            'status' => $this->status((int) ($arguments['task_id'] ?? 0)),
            'list' => $this->list((int) ($arguments['limit'] ?? 10)),
            default => throw new RuntimeException('Unsupported task_engine action.'),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function create(array $arguments): string
    {
        $goal = trim((string) ($arguments['goal'] ?? ''));

        if ($goal === '') {
            throw new RuntimeException('goal is required to create a task.');
        }

        $userId = auth()->id();

        $task = $this->engine()->create(
            goal: $goal,
            conversationId: isset($arguments['conversation_id']) ? (int) $arguments['conversation_id'] : null,
            userId: $userId,
            acceptanceCriteria: array_values(array_filter(
                array_map('strval', $arguments['acceptance_criteria'] ?? []),
                fn (string $c): bool => trim($c) !== '',
            )),
        );

        ExecuteTaskStepJob::dispatch($task->id);

        return "Tracked task #{$task->id} created and execution dispatched — it continues automatically until done or verified."
            ." Status: {$task->status->value}. Progress is visible on the Agent Tasks page (/agent-tasks/{$task->id})."
            .' Only report progress pulled from task_engine status afterward; do not claim steps are done before the engine records them.';
    }

    private function enqueue(int $taskId): string
    {
        if ($taskId < 1) {
            throw new RuntimeException('task_id is required.');
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            return "Task #{$taskId} does not exist.";
        }

        if (in_array($task->status->value, ['completed', 'failed', 'cancelled'], true)) {
            return "Task #{$taskId} is already in a final state ({$task->status->value}); nothing to execute.";
        }

        ExecuteTaskStepJob::dispatch($taskId);

        return "Task #{$taskId} re-queued for execution. Current: {$task->status->value}, step {$task->current_step}/{$task->total_steps}.";
    }

    private function status(int $taskId): string
    {
        if ($taskId < 1) {
            throw new RuntimeException('task_id is required.');
        }

        $task = Task::query()->with(['steps', 'latestCheckpoint'])->find($taskId);

        if ($task === null) {
            return "Task #{$taskId} does not exist.";
        }

        $blockers = $this->engine()->completionBlockers($task);

        $lines = [
            "Task #{$task->id}: {$task->status->value} ({$task->status->label()})",
            "Progress: step {$task->current_step} of {$task->total_steps}",
        ];

        $unfinished = $task->steps
            ->filter(fn (TaskStep $s): bool => ! in_array($s->status->value, ['completed', 'skipped'], true))
            ->sortBy('sort_order')
            ->first();

        if ($unfinished !== null) {
            $lines[] = "Next unfinished step: {$unfinished->description} [{$unfinished->status->value}]";
        }

        if ($task->steps->count() === 0 && $task->status->value === 'pending') {
            $lines[1] .= ' (plan not generated yet)';
        }

        if (($checkpoint = $task->latestCheckpoint) !== null) {
            $lines[] = "Latest checkpoint: {$checkpoint->action_taken}".($checkpoint->action_result ? ' — '.substr($checkpoint->action_result, 0, 200) : '');
        }

        if ($task->last_error !== null) {
            $lines[] = 'Last error: '.substr($task->last_error, 0, 300);
        }

        if ($task->verification_status !== null) {
            $lines[] = "Verification: {$task->verification_status}".($task->last_verified_at ? " at {$task->last_verified_at->toDateTimeString()}" : '');
        }

        if ($blockers !== []) {
            $lines[] = 'Still blocking on: '.implode(', ', array_keys($blockers));
        }

        return implode("\n", $lines);
    }

    private function engine(): TaskEngine
    {
        return $this->container->make(TaskEngine::class);
    }

    private function list(int $limit): string
    {
        $tasks = Task::query()
            ->with('latestCheckpoint')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 25)))
            ->get();

        if ($tasks->isEmpty()) {
            return 'No tasks yet.';
        }

        return $tasks->map(fn (Task $t): string => sprintf(
            '[#%d] %s — step %d/%d — %s%s',
            $t->id,
            $t->status->value,
            $t->current_step,
            $t->total_steps,
            substr($t->goal, 0, 70),
            $t->latestCheckpoint ? ' — '.substr($t->latestCheckpoint->action_taken, 0, 60) : '',
        ))->implode("\n");
    }
}
