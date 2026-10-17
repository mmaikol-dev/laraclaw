<?php

namespace App\Services\Tools;

use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
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
            'retry revives a task the user explicitly asks to bring back: it restarts a failed task (or recovers a stalled one).',
            'Retrying is strictly user-initiated — never retry a task on your own initiative or as a "just in case" step; only when the user directly asks you to retry/resume a specific task.',
            'stale lists tasks that have gone quiet so you can report them; restart them only via retry once the user asks.',
            'If a task will need elevated (sudo) privileges, set requires_sudo=true on create (or call request_sudo) and ask the user to approve it — sudo is never granted automatically.',
        ]);
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'execute', 'status', 'list', 'retry', 'stale', 'request_sudo'],
                    'description' => implode(' ', [
                        'create: create a task (plan is generated, execution dispatched immediately).',
                        'execute: force-enqueue an existing task now.',
                        'status: read the REAL persisted progress of a task.',
                        'list: show the most recent tasks and their states.',
                        'retry: revive a task the user explicitly asks to retry — restarts a failed task or recovers a stalled one. Requires confirmed_by_user=true.',
                        'stale: list tasks that have stopped making progress (stale/heartbeat lost) and tasks awaiting human input.',
                        'request_sudo: ask the user for elevated (sudo) permission for a specific task; the task pauses until they approve it.',
                    ]),
                ],
                'goal' => ['type' => 'string', 'description' => 'The concrete goal of the task.'],
                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional objective checks used for verification.'],
                'conversation_id' => ['type' => 'integer', 'description' => 'Optional conversation id to stream step work into. Omit to give the task its own private conversation.'],
                'task_id' => ['type' => 'integer', 'description' => 'Task id for execute/status/retry/request_sudo.'],
                'limit' => ['type' => 'integer', 'description' => 'For list/stale: how many tasks to show (default 10).'],
                'requires_sudo' => ['type' => 'boolean', 'description' => 'For create: set true when the task is expected to need elevated (sudo) privileges. The task is created but will not start until the user approves sudo for it.'],
                'reason' => ['type' => 'string', 'description' => 'For request_sudo (and create with requires_sudo): a short explanation of why sudo is needed, shown to the user for approval.'],
                'confirmed_by_user' => ['type' => 'boolean', 'description' => 'For retry: must be true. Set it ONLY after the user has explicitly asked (in their own message) to retry this specific failed or stalled task. Never set it on your own initiative.'],
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
            'retry' => $this->retry($arguments),
            'stale' => $this->stale((int) ($arguments['limit'] ?? 10)),
            'request_sudo' => $this->requestSudo($arguments),
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

        if (($arguments['requires_sudo'] ?? false) === true) {
            $reason = trim((string) ($arguments['reason'] ?? ''))
                ?: 'This task is expected to need elevated (sudo) privileges.';

            $task->requestSudo($reason);
            $this->engine()->park($task, "Awaiting sudo approval: {$reason}", TaskFailureType::PermissionError);

            return "Tracked task #{$task->id} created, but it will NOT start until sudo is approved. "
                ."Tell the user it needs elevated (sudo) permission and ask them to approve or deny it on /agent-tasks/{$task->id}. "
                .'Once approved, execution begins automatically.';
        }

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

    /**
     * Revive a failed task or recover a stalled one.
     *
     * This is deliberately user-initiated: the engine has no autonomous retry
     * path, and the agent must only call this after the user explicitly asks.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function retry(array $arguments): string
    {
        $taskId = (int) ($arguments['task_id'] ?? 0);

        if ($taskId < 1) {
            throw new RuntimeException('task_id is required.');
        }

        if (($arguments['confirmed_by_user'] ?? false) !== true) {
            return "Not retrying task #{$taskId}: this action is user-initiated. Ask the user to explicitly confirm they want to retry the failed or stalled task first, then call retry with confirmed_by_user=true.";
        }

        $task = Task::query()->with('steps')->find($taskId);

        if ($task === null) {
            return "Task #{$taskId} does not exist.";
        }

        if ($task->status === TaskStatus::Completed) {
            return "Task #{$taskId} is already completed — there is nothing to retry.";
        }

        if ($task->status === TaskStatus::Cancelled) {
            return "Task #{$taskId} was cancelled. The agent will not silently restart a cancelled task; create a new task if the user wants to redo the work.";
        }

        if ($task->status === TaskStatus::Failed) {
            $failedStep = $task->steps
                ->filter(fn (TaskStep $step): bool => $step->status === TaskStepStatus::Failed)
                ->sortBy('sort_order')
                ->first();

            if ($failedStep instanceof TaskStep) {
                $this->engine()->restartFailedStep($task, $failedStep->sort_order);
                ExecuteTaskStepJob::dispatch($taskId);

                return "Retrying task #{$taskId}: reset failed step {$failedStep->sort_order} ({$failedStep->description}) and re-queued it. It continues automatically; check progress with status.";
            }

            $this->engine()->prepareResume($task);
            ExecuteTaskStepJob::dispatch($taskId);

            return "Retrying task #{$taskId}: no failed step on record, so it was re-queued from its last checkpoint. Check progress with status.";
        }

        $stallable = in_array($task->status, [
            TaskStatus::Running,
            TaskStatus::Retrying,
            TaskStatus::Verifying,
            TaskStatus::Planning,
        ], true);

        if ($stallable && $task->isStale()) {
            $recovery = $this->engine()->recover($task);
            ExecuteTaskStepJob::dispatch($taskId);

            return "Recovered stalled task #{$taskId} from step ".($recovery['resumed_from'] ?? 0).' and re-queued it. Check progress with status.';
        }

        if (in_array($task->status, [TaskStatus::Paused, TaskStatus::Waiting], true)) {
            return "Task #{$taskId} is {$task->status->value} on purpose and is not stalled; not touching it. Ask the user whether they want it resumed.";
        }

        return "Task #{$taskId} is currently active ({$task->status->value}) and not stalled; leaving it to continue on its own. Use status to monitor it.";
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

    /**
     * Report tasks that have stopped making progress and tasks awaiting human
     * input, so the agent can surface them instead of silently retrying.
     */
    private function stale(int $limit): string
    {
        $limit = max(1, min($limit, 25));
        $grace = (int) config('agent.task.stale_grace_seconds', 120);

        $staleTasks = Task::query()
            ->active()
            ->where('status', '!=', TaskStatus::Paused)
            ->where('status', '!=', TaskStatus::Waiting)
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds($grace))
            ->orderBy('last_heartbeat_at')
            ->limit($limit)
            ->get();

        $lines = [];

        if ($staleTasks->isEmpty()) {
            $lines[] = 'No stale tasks.';
        } else {
            foreach ($staleTasks as $task) {
                $lines[] = sprintf(
                    '[#%d] stale %s — step %d/%d — last heartbeat %s — %s',
                    $task->id,
                    $task->status->value,
                    $task->current_step,
                    $task->total_steps,
                    $task->last_heartbeat_at?->diffForHumans() ?? 'never',
                    substr($task->goal, 0, 60),
                );
            }
        }

        $needsHuman = Task::query()
            ->where('needs_human_input', true)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($needsHuman->isNotEmpty()) {
            $lines[] = 'Awaiting human input: '.$needsHuman
                ->map(fn (Task $t): string => "#{$t->id} (".($t->failure_type?->value ?? 'unknown').')')
                ->implode(', ');
        }

        $lines[] = 'Restart a stale task only when the user explicitly asks, using action=retry with confirmed_by_user=true.';

        return implode("\n", $lines);
    }

    /**
     * Ask the user for elevated (sudo) permission for a specific task. The task
     * is parked until the user approves or denies it in the UI.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function requestSudo(array $arguments): string
    {
        $taskId = (int) ($arguments['task_id'] ?? 0);

        if ($taskId < 1) {
            throw new RuntimeException('task_id is required.');
        }

        $task = Task::query()->find($taskId);

        if ($task === null) {
            return "Task #{$taskId} does not exist.";
        }

        if ($task->sudoAllowed()) {
            return "Task #{$taskId} already has sudo approved for it; proceed with elevated commands.";
        }

        $reason = trim((string) ($arguments['reason'] ?? ''))
            ?: 'This task needs elevated (sudo) privileges.';

        $task->requestSudo($reason);

        if (! $task->status->isTerminal() && $task->status !== TaskStatus::Waiting) {
            $this->engine()->park($task, "Awaiting sudo approval: {$reason}", TaskFailureType::PermissionError);
        }

        return "Sudo approval requested for task #{$taskId}. Ask the user to approve or deny it on /agent-tasks/{$taskId}. "
            .'The task stays paused and no elevated command will run until it is approved.';
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
