<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\TaskEngine\SpecialistAgents;
use App\Services\TaskEngine\TaskContext;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentTasksController extends Controller
{
    public function __construct(
        protected TaskContext $context,
        protected SpecialistAgents $specialists,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->input('status');

        $tasks = Task::query()
            ->with(['steps', 'latestCheckpoint'])
            ->when($status, fn ($q, string $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        $staleThreshold = now()->subSeconds((int) config('agent.task.stale_grace_seconds', 120));

        $tasks->getCollection()->transform(function (Task $task) use ($staleThreshold): array {
            return $this->serializeForList($task, $staleThreshold);
        });

        return Inertia::render('agent-tasks/index', [
            'tasks' => $tasks,
            'filters' => ['status' => $status],
            'metrics' => [
                'active' => Task::query()->whereIn('status', ['planning', 'running', 'retrying', 'verifying'])->count(),
                'paused' => Task::query()->where('status', 'paused')->count(),
                'completed' => Task::query()->where('status', 'completed')->count(),
                'failed' => Task::query()->where('status', 'failed')->count(),
                'stale' => Task::query()->whereIn('status', ['running', 'waiting'])->where('last_heartbeat_at', '<', $staleThreshold)->count(),
            ],
        ]);
    }

    public function show(Task $task): Response
    {
        $task->load(['steps', 'checkpoints']);

        return Inertia::render('agent-tasks/show', [
            'task' => [
                'id' => $task->id,
                'goal' => $task->goal,
                'status' => $task->status->value,
                'status_label' => $task->status->label(),
                'plan' => $task->plan,
                'current_step' => $task->current_step,
                'total_steps' => $task->total_steps,
                'execution_state' => $task->execution_state,
                'attempts' => $task->attempts,
                'max_attempts' => $task->max_attempts,
                'failure_type' => $task->failure_type,
                'failure_reason' => $task->failure_reason,
                'last_action' => $task->last_action,
                'last_result' => $task->last_result,
                'last_error' => $task->last_error,
                'verification_status' => $task->verification_status,
                'acceptance_criteria' => $task->acceptance_criteria ?? [],
                'verification_results' => $task->verification_results,
                'last_verified_at' => $task->last_verified_at?->toIso8601String(),
                'model' => $task->model,
                'complexity_level' => $task->complexity_level,
                'last_heartbeat_at' => $task->last_heartbeat_at?->toIso8601String(),
                'started_at' => $task->started_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
                'steps' => $task->steps->map(fn ($step): array => [
                    'id' => $step->id,
                    'sort_order' => $step->sort_order,
                    'description' => $step->description,
                    'prompt' => $step->prompt,
                    'status' => $step->status->value,
                    'status_label' => $step->status->label(),
                    'attempts' => $step->attempts,
                    'max_attempts' => $step->max_attempts,
                    'result' => $step->result,
                    'error' => $step->error,
                    'repaired' => $step->repaired,
                ])->values(),
                'checkpoints' => $task->checkpoints->map(fn ($checkpoint): array => [
                    'id' => $checkpoint->id,
                    'step_sort_order' => $checkpoint->step_sort_order,
                    'action_taken' => $checkpoint->action_taken,
                    'action_result' => $checkpoint->action_result,
                    'task_status' => $checkpoint->task_status,
                    'metadata' => $checkpoint->metadata,
                    'created_at' => $checkpoint->created_at?->toIso8601String(),
                ])->values(),
            ],
            'context' => $this->context->snapshot($task),
            'context_summary' => $this->context->buildSummary($task),
            'specialists' => $this->specialists->summary(),
        ]);
    }

    /**
     * @param  CarbonInterface  $staleThreshold  Accepts both Carbon and CarbonImmutable, since the app configures Date::use(CarbonImmutable::class).
     */
    private function serializeForList(Task $task, CarbonInterface $staleThreshold): array
    {
        $checkpoint = $task->latestCheckpoint;

        return [
            'id' => $task->id,
            'goal' => $task->goal,
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'current_step' => $task->current_step,
            'total_steps' => $task->total_steps,
            'progress_percentage' => $task->progressPercentage(),
            'attempts' => $task->attempts,
            'max_attempts' => $task->max_attempts,
            'failure_reason' => $task->failure_reason,
            'model' => $task->model,
            'complexity_level' => $task->complexity_level,
            'steps_count' => $task->steps->count(),
            'is_stale' => in_array($task->status->value, ['running', 'planning', 'waiting'], true)
                && ($task->last_heartbeat_at === null || $task->last_heartbeat_at->lt($staleThreshold)),
            'last_heartbeat_at' => $task->last_heartbeat_at?->toIso8601String(),
            'last_action' => $task->last_action,
            'started_at' => $task->started_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
            'latest_checkpoint' => $checkpoint === null ? null : [
                'action_taken' => $checkpoint->action_taken,
                'created_at' => $checkpoint->created_at?->toIso8601String(),
            ],
        ];
    }
}
