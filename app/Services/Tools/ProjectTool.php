<?php

namespace App\Services\Tools;

use App\Jobs\ContinueProjectJob;
use App\Models\Project;
use RuntimeException;

class ProjectTool extends BaseTool
{
    public function getName(): string
    {
        return 'project';
    }

    public function getDescription(): string
    {
        return 'Manage multi-day projects and their tasks. Create goals, break them into tasks, track progress, and continue work across sessions.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'read', 'create', 'update', 'add_task', 'update_task', 'complete', 'add_note', 'continue'],
                    'description' => implode(' ', [
                        'list: show all projects.',
                        'read: get full project details and task list.',
                        'create: start a new project with a goal.',
                        'update: change project name, description, goal, status, or due_date.',
                        'add_task: add a task to a project.',
                        'update_task: update a task status or notes — pass project_name, task_title, status, notes.',
                        'complete: mark a project as completed.',
                        'add_note: append a progress note to the project.',
                        'continue: queue the agent to continue working on this project autonomously.',
                    ]),
                ],
                'project_name' => ['type' => 'string', 'description' => 'Project name.'],
                'name' => ['type' => 'string', 'description' => 'New project name (create).'],
                'description' => ['type' => 'string', 'description' => 'Project description.'],
                'goal' => ['type' => 'string', 'description' => 'The project goal/outcome.'],
                'due_date' => ['type' => 'string', 'description' => 'Due date YYYY-MM-DD (optional).'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'active', 'paused', 'completed'], 'description' => 'Project status.'],
                'task_title' => ['type' => 'string', 'description' => 'Task title (add_task/update_task).'],
                'task_description' => ['type' => 'string', 'description' => 'Task description (add_task).'],
                'task_status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'done', 'blocked'], 'description' => 'Task status (update_task).'],
                'task_notes' => ['type' => 'string', 'description' => 'Notes about this task (update_task).'],
                'note' => ['type' => 'string', 'description' => 'Progress note to append (add_note).'],
            ],
            'required' => ['action'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'list' => $this->list(),
            'read' => $this->read((string) ($arguments['project_name'] ?? '')),
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'add_task' => $this->addTask($arguments),
            'update_task' => $this->updateTask($arguments),
            'complete' => $this->complete((string) ($arguments['project_name'] ?? '')),
            'add_note' => $this->addNote($arguments),
            'continue' => $this->continueProject((string) ($arguments['project_name'] ?? '')),
            default => throw new RuntimeException('Unsupported project action.'),
        };
    }

    private function list(): string
    {
        $projects = Project::orderByRaw("FIELD(status,'active','pending','paused','completed')")->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return 'No projects yet.';
        }

        $lines = $projects->map(fn ($p) => sprintf(
            '[%s] %s — %s | %s',
            strtoupper($p->status),
            $p->name,
            $p->goal,
            $p->progressSummary(),
        ))->implode("\n");

        return "Projects ({$projects->count()}):\n\n{$lines}";
    }

    private function read(string $name): string
    {
        $project = $this->findOrFail($name);
        $tasks = $project->tasks;

        $taskLines = $tasks->isEmpty()
            ? '  (no tasks yet)'
            : $tasks->map(fn ($t) => "  [{$t->status}] {$t->title}".($t->notes ? "\n    Note: {$t->notes}" : ''))->implode("\n");

        $due = $project->due_date ? "\nDue: {$project->due_date->toDateString()}" : '';
        $notes = $project->progress_notes ? "\n\nProgress notes:\n{$project->progress_notes}" : '';

        return <<<OUT
=== {$project->name} ===
Status: {$project->status}
Goal: {$project->goal}{$due}
Progress: {$project->progressSummary()}

Tasks:
{$taskLines}{$notes}
OUT;
    }

    /** @param array<string, mixed> $args */
    private function create(array $args): string
    {
        $name = trim((string) ($args['name'] ?? ''));
        $goal = trim((string) ($args['goal'] ?? ''));

        if ($name === '' || $goal === '') {
            throw new RuntimeException('name and goal are required.');
        }

        if (Project::where('name', $name)->exists()) {
            throw new RuntimeException("Project '{$name}' already exists.");
        }

        Project::create([
            'name' => $name,
            'description' => $args['description'] ?? null,
            'goal' => $goal,
            'status' => 'pending',
            'due_date' => $args['due_date'] ?? null,
        ]);

        return "Project '{$name}' created. Use add_task to define the tasks, then continue to start working on it.";
    }

    /** @param array<string, mixed> $args */
    private function update(array $args): string
    {
        $project = $this->findOrFail((string) ($args['project_name'] ?? ''));

        $fields = array_filter([
            'description' => $args['description'] ?? null,
            'goal' => $args['goal'] ?? null,
            'status' => $args['status'] ?? null,
            'due_date' => $args['due_date'] ?? null,
        ], fn ($v) => $v !== null);

        if ($fields === []) {
            throw new RuntimeException('No fields to update.');
        }

        $project->update($fields);

        return "Project '{$project->name}' updated.";
    }

    /** @param array<string, mixed> $args */
    private function addTask(array $args): string
    {
        $project = $this->findOrFail((string) ($args['project_name'] ?? ''));
        $title = trim((string) ($args['task_title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('task_title is required.');
        }

        $order = $project->tasks()->max('sort_order') + 1;

        $project->tasks()->create([
            'title' => $title,
            'description' => $args['task_description'] ?? null,
            'status' => 'pending',
            'sort_order' => $order,
        ]);

        return "Task '{$title}' added to project '{$project->name}'.";
    }

    /** @param array<string, mixed> $args */
    private function updateTask(array $args): string
    {
        $project = $this->findOrFail((string) ($args['project_name'] ?? ''));
        $title = trim((string) ($args['task_title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('task_title is required.');
        }

        $task = $project->tasks()->where('title', 'like', "%{$title}%")->first();

        if ($task === null) {
            throw new RuntimeException("Task '{$title}' not found in project '{$project->name}'.");
        }

        $fields = array_filter([
            'status' => $args['task_status'] ?? null,
            'notes' => $args['task_notes'] ?? null,
        ], fn ($v) => $v !== null);

        if (($fields['status'] ?? null) === 'done') {
            $fields['completed_at'] = now();
        }

        $task->update($fields);

        return "Task '{$task->title}' updated to [{$task->fresh()?->status}].";
    }

    private function complete(string $name): string
    {
        $project = $this->findOrFail($name);
        $project->update(['status' => 'completed', 'completed_at' => now()]);

        return "Project '{$name}' marked as completed.";
    }

    /** @param array<string, mixed> $args */
    private function addNote(array $args): string
    {
        $project = $this->findOrFail((string) ($args['project_name'] ?? ''));
        $note = trim((string) ($args['note'] ?? ''));

        if ($note === '') {
            throw new RuntimeException('note is required.');
        }

        $existing = $project->progress_notes ? $project->progress_notes."\n" : '';
        $project->update(['progress_notes' => $existing.'['.now()->toDateTimeString().'] '.$note]);

        return "Note added to project '{$project->name}'.";
    }

    private function continueProject(string $name): string
    {
        $project = $this->findOrFail($name);
        ContinueProjectJob::dispatch($project->id);

        return "Project '{$name}' queued for autonomous continuation.";
    }

    private function findOrFail(string $name): Project
    {
        if ($name === '') {
            throw new RuntimeException('project_name is required.');
        }

        $project = Project::where('name', $name)->first();

        if ($project === null) {
            throw new RuntimeException("Project '{$name}' not found.");
        }

        return $project;
    }
}
