<?php

namespace App\Services\Tools;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTask;
use RuntimeException;

class ScheduledTaskTool extends BaseTool
{
    public function getName(): string
    {
        return 'scheduled_task';
    }

    public function getDescription(): string
    {
        return 'Create and manage recurring scheduled tasks — routines that trigger automatically on a cron schedule and run the agent autonomously.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete', 'run_now', 'pause', 'resume'],
                    'description' => implode(' ', [
                        'list: show all scheduled tasks.',
                        'create: define a new recurring task.',
                        'update: modify an existing task.',
                        'delete: remove a task.',
                        'run_now: trigger a task immediately.',
                        'pause: disable a task without deleting.',
                        'resume: re-enable a paused task.',
                    ]),
                ],
                'name' => ['type' => 'string', 'description' => 'Task name.'],
                'description' => ['type' => 'string', 'description' => 'What this task does.'],
                'cron_expression' => ['type' => 'string', 'description' => 'Cron expression e.g. "0 9 * * 1-5" (weekdays at 9am), "*/30 * * * *" (every 30 min).'],
                'prompt' => ['type' => 'string', 'description' => 'The prompt to run when this task fires.'],
                'use_same_conversation' => ['type' => 'boolean', 'description' => 'Reuse the same conversation across runs (default false = fresh each time).'],
            ],
            'required' => ['action'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'list' => $this->list(),
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'delete' => $this->delete((string) ($arguments['name'] ?? '')),
            'run_now' => $this->runNow((string) ($arguments['name'] ?? '')),
            'pause' => $this->setPaused((string) ($arguments['name'] ?? ''), false),
            'resume' => $this->setPaused((string) ($arguments['name'] ?? ''), true),
            default => throw new RuntimeException('Unsupported scheduled_task action.'),
        };
    }

    private function list(): string
    {
        $tasks = ScheduledTask::orderBy('name')->get();

        if ($tasks->isEmpty()) {
            return 'No scheduled tasks defined yet.';
        }

        $lines = $tasks->map(fn ($t) => sprintf(
            '[%s] %s | cron: %s | last: %s | next: %s',
            $t->is_active ? '✓' : '✗',
            $t->name,
            $t->cron_expression,
            $t->last_run_at?->diffForHumans() ?? 'never',
            $t->next_run_at?->diffForHumans() ?? 'unknown',
        ))->implode("\n");

        return "Scheduled tasks ({$tasks->count()}):\n\n{$lines}";
    }

    /** @param array<string, mixed> $args */
    private function create(array $args): string
    {
        $name = trim((string) ($args['name'] ?? ''));
        $cron = trim((string) ($args['cron_expression'] ?? ''));
        $prompt = trim((string) ($args['prompt'] ?? ''));

        if ($name === '' || $cron === '' || $prompt === '') {
            throw new RuntimeException('name, cron_expression, and prompt are required.');
        }

        if (ScheduledTask::where('name', $name)->exists()) {
            throw new RuntimeException("Scheduled task '{$name}' already exists.");
        }

        $task = ScheduledTask::create([
            'name' => $name,
            'description' => $args['description'] ?? null,
            'cron_expression' => $cron,
            'prompt' => $prompt,
            'use_same_conversation' => (bool) ($args['use_same_conversation'] ?? false),
            'is_active' => true,
        ]);

        $task->updateNextRun();

        return "Scheduled task '{$name}' created. Next run: {$task->fresh()?->next_run_at?->toDateTimeString()}.";
    }

    /** @param array<string, mixed> $args */
    private function update(array $args): string
    {
        $name = trim((string) ($args['name'] ?? ''));
        $task = $this->findOrFail($name);

        $fields = array_filter([
            'description' => $args['description'] ?? null,
            'cron_expression' => $args['cron_expression'] ?? null,
            'prompt' => $args['prompt'] ?? null,
            'use_same_conversation' => isset($args['use_same_conversation']) ? (bool) $args['use_same_conversation'] : null,
        ], fn ($v) => $v !== null);

        if ($fields === []) {
            throw new RuntimeException('No fields to update.');
        }

        $task->update($fields);

        return "Scheduled task '{$name}' updated.";
    }

    private function delete(string $name): string
    {
        $this->findOrFail($name)->delete();

        return "Scheduled task '{$name}' deleted.";
    }

    private function runNow(string $name): string
    {
        $task = $this->findOrFail($name);
        RunScheduledTaskJob::dispatch($task->id);

        return "Scheduled task '{$name}' queued for immediate execution.";
    }

    private function setPaused(string $name, bool $active): string
    {
        $task = $this->findOrFail($name);
        $task->update(['is_active' => $active]);

        return "Scheduled task '{$name}' ".($active ? 'resumed' : 'paused').'.';
    }

    private function findOrFail(string $name): ScheduledTask
    {
        if ($name === '') {
            throw new RuntimeException('name is required.');
        }

        return ScheduledTask::where('name', $name)->firstOrFail();
    }
}
