<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;
use App\Services\TaskEngine\TaskEngine;
use App\Services\TaskEngine\TaskSupervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskEngineController extends Controller
{
    public function __construct(
        protected TaskEngine $engine,
        protected TaskSupervisor $supervisor,
    ) {}

    /**
     * List tasks, optionally filtered by status/user.
     */
    public function index(Request $request): JsonResponse
    {
        $tasks = Task::query()
            ->with(['steps', 'latestCheckpoint'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($tasks);
    }

    /**
     * Create a new task.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'goal' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'acceptance_criteria' => ['nullable', 'array'],
            'complexity' => ['nullable', 'string', 'in:simple,coding,reasoning,review,complex'],
        ]);

        $task = $this->engine->create(
            goal: $validated['goal'],
            conversationId: $validated['conversation_id'] ?? null,
            userId: $request->user()?->id,
            acceptanceCriteria: $validated['acceptance_criteria'] ?? [],
            complexity: $validated['complexity'] ?? null,
        );

        return response()->json($task, 201);
    }

    /**
     * Show a single task with its full execution state.
     */
    public function show(Task $task): JsonResponse
    {
        $task->load(['steps', 'checkpoints']);

        return response()->json($task);
    }

    /**
     * Execute (or resume) a task.
     *
     * Enqueues the task for queue-driven execution and returns immediately. The
     * task continues automatically through ExecuteTaskStepJob until it reaches
     * a terminal state — the user never has to send "continue".
     */
    public function execute(Request $request, Task $task): JsonResponse
    {
        ExecuteTaskStepJob::dispatch($task->id);
        $task->load(['steps', 'latestCheckpoint']);

        return response()->json([
            'accepted' => true,
            'status' => $task->status->value,
            'task' => $task,
        ], 202);
    }

    /**
     * Pause a running task.
     */
    public function pause(Request $request, Task $task): JsonResponse
    {
        $this->engine->pause($task);

        return response()->json(['status' => $task->status->value]);
    }

    /**
     * Cancel a task cooperatively.
     */
    public function cancel(Request $request, Task $task): JsonResponse
    {
        $this->engine->cancel($task);

        return response()->json(['status' => $task->status->value]);
    }

    /**
     * Put a task into a cooperative waiting state (e.g. awaiting approval).
     */
    public function wait(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'resume_condition' => ['nullable', 'array'],
        ]);

        $this->engine->wait(
            $task,
            $validated['reason'],
            $validated['resume_condition'] ?? null,
        );

        return response()->json(['status' => $task->status->value]);
    }

    /**
     * Resume a task from its latest checkpoint.
     *
     * This re-positions the task and enqueues execution; normal operation
     * continues automatically and does not require this call.
     */
    public function resume(Request $request, Task $task): JsonResponse
    {
        $this->engine->prepareResume($task);
        ExecuteTaskStepJob::dispatch($task->id);

        return response()->json(['accepted' => true, 'status' => $task->status->value], 202);
    }

    /**
     * Recover a stalled task and re-queue it for automatic continuation.
     */
    public function recover(Request $request, Task $task): JsonResponse
    {
        $recovery = $this->engine->recover($task);
        ExecuteTaskStepJob::dispatch($task->id);

        return response()->json($recovery);
    }

    /**
     * Restart a failed step and re-queue execution.
     */
    public function restartStep(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'sort_order' => ['required', 'integer'],
        ]);

        $step = $this->engine->restartFailedStep($task, $validated['sort_order']);
        ExecuteTaskStepJob::dispatch($task->id);

        return response()->json(['step' => $step]);
    }

    /**
     * Re-plan a task (regenerate steps) and re-queue execution.
     */
    public function replan(Request $request, Task $task): JsonResponse
    {
        $task->steps()->delete();
        $task->update(['current_step' => 0, 'total_steps' => 0, 'status' => 'pending']);

        $steps = $this->engine->plan($task);
        ExecuteTaskStepJob::dispatch($task->id);

        return response()->json(['steps' => count($steps)]);
    }

    /**
     * Run the supervisor/watchdog immediately (diagnostics).
     */
    public function supervise(Request $request): JsonResponse
    {
        $actions = $this->supervisor->inspect();

        return response()->json(['actions' => $actions]);
    }

    /**
     * Delete a task.
     */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $task->delete();

        return response()->json([], 204);
    }
}
