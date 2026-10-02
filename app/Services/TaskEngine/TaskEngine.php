<?php

namespace App\Services\TaskEngine;

use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Events\TaskCancelled;
use App\Events\TaskCheckpointCreated;
use App\Events\TaskCompleted;
use App\Events\TaskCreated;
use App\Events\TaskEscalated;
use App\Events\TaskFailed;
use App\Events\TaskPlanned;
use App\Events\TaskRetrying;
use App\Events\TaskStarted;
use App\Events\TaskStepStarted;
use App\Events\TaskVerificationFailed;
use App\Events\TaskVerificationPassed;
use App\Events\TaskVerificationStarted;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The task engine is the source of truth for task state, progress, checkpoints,
 * retries, verification, recovery and completion. The model is an executor
 * within this controlled framework — it never marks a task complete itself.
 */
class TaskEngine
{
    public function __construct(
        protected ToolRegistry $tools,
        protected ModelRouter $router,
        protected FailureClassifier $classifier,
        protected LoopDetector $loopDetector,
        protected VerificationService $verifier,
        protected AgentService $agent,
        protected SpecialistAgents $specialists,
        protected TaskContext $context,
    ) {}

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    /**
     * Create a new task and persist it.
     */
    public function create(
        string $goal,
        ?int $conversationId = null,
        ?int $userId = null,
        array $acceptanceCriteria = [],
        ?string $complexity = null,
    ): Task {
        $complexity ??= $this->router->classifyComplexity($goal);
        $model = $this->router->modelForComplexity($complexity);

        $task = Task::query()->create([
            'goal' => $goal,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'status' => TaskStatus::Pending,
            'acceptance_criteria' => $acceptanceCriteria ?: null,
            'complexity_level' => $complexity,
            'model' => $model,
            'max_attempts' => (int) config('agent.task.max_attempts', 5),
            'heartbeat_interval_seconds' => (int) config('agent.task.heartbeat_interval_seconds', 90),
            'current_step' => 0,
            'total_steps' => 0,
            'attempts' => 0,
        ]);

        event(new TaskCreated($task));

        return $task;
    }

    // -------------------------------------------------------------------------
    // Planning
    // -------------------------------------------------------------------------

    /**
     * Build a plan for a task. Uses the model to decompose the goal into steps,
     * then persists the plan as TaskStep rows.
     *
     * @return array<int, TaskStep>
     */
    public function plan(Task $task, array $steps = []): array
    {
        if (! $task->status->canTransitionTo(TaskStatus::Planning)) {
            $task->transitionTo(TaskStatus::Planning);
        }

        if ($steps === [] && (bool) config('agent.task.planning_enabled', true)) {
            $steps = $this->generatePlan($task);
        }

        if ($steps === [] || $steps === [[]]) {
            // Fall back to a single "execute" step if planning yielded nothing.
            $steps = [['description' => $task->goal, 'prompt' => $task->goal]];
        }

        $persisted = [];
        $order = 1;

        foreach ($steps as $step) {
            $persisted[] = $task->steps()->create([
                'sort_order' => $order,
                'description' => $step['description'] ?? 'Execute task',
                'prompt' => $step['prompt'] ?? ($step['description'] ?? null),
                'depends_on' => $step['depends_on'] ?? null,
                'max_attempts' => (int) config('agent.task.max_step_attempts', 3),
            ]);

            $order++;
        }

        $task->update([
            'plan' => json_encode(array_map(
                fn (TaskStep $s): array => [
                    'order' => $s->sort_order,
                    'description' => $s->description,
                    'prompt' => $s->prompt,
                ],
                $persisted,
            ), JSON_THROW_ON_ERROR),
            'total_steps' => count($persisted),
            'status' => TaskStatus::Planning,
        ]);

        event(new TaskPlanned($task));

        return $persisted;
    }

    /**
     * Ask the model to propose a plan for the task.
     *
     * @return array<int, array{description: string, prompt?: string, depends_on?: array<int, int>}>
     */
    public function generatePlan(Task $task): array
    {
        $prompt = $this->buildPlanningPrompt($task);
        $history = [
            ['role' => 'system', 'content' => 'You are the planning module of a task engine. Given a goal, produce a concrete numbered execution plan.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        $planner = $this->specialists->bySlug('planner');
        $response = $this->agent->chatRaw(
            $history,
            model: $planner === null ? $this->router->modelForTask($task) : $this->router->modelForSpecialist($planner),
            temperature: 0.2,
        );

        return $this->parsePlan($response);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Execute the next step of a task (or resume). Initializes state, marks the
     * task running, then advances through pending steps, checkpointing each one.
     *
     * This remains available as a synchronous convenience (used by tests and
     * inline tooling). Production queue-driven execution uses ExecuteTaskStepJob,
     * which performs one step per job and automatically dispatches the next.
     *
     * @param  null|callable(array<string, mixed>): void  $listener
     */
    public function execute(Task $task, ?callable $listener = null, ?int $fromStep = null): TaskResult
    {
        if ($task->status === TaskStatus::Pending) {
            if ($task->steps()->count() === 0) {
                $this->plan($task);
            } else {
                $task->transitionTo(TaskStatus::Planning);
            }
        }

        $task->markRunning();
        event(new TaskStarted($task));
        $this->notify($listener, ['type' => 'status', 'status' => 'running', 'label' => 'Task running']);

        try {
            while (in_array($task->status, [TaskStatus::Running, TaskStatus::Retrying], true)) {
                // Ensure the task is Running (not stuck in Retrying) before stepping.
                if ($task->status === TaskStatus::Retrying) {
                    $task->forceFill(['status' => TaskStatus::Running])->save();
                }

                $this->executeNextStep($task, $listener);
                $task->refresh();

                if ($task->status->isTerminal()) {
                    break;
                }

                // All steps done; run verification before completing.
                if (! $task->hasUnfinishedSteps()) {
                    $this->finalizeTask($task, $listener);
                    $task->refresh();
                }
            }
        } catch (Throwable $exception) {
            return $this->fail($task, $exception->getMessage(), $this->classifier->classify($exception->getMessage()), $listener);
        }

        return TaskResult::fromTask($task);
    }

    /**
     * Execute exactly one step of a task within a single job, then persist its
     * checkpoint. This is the unit of work for queue-driven execution.
     *
     * Returns true when more steps remain and execution should continue, false
     * when the task reached a terminal (or verifying) state.
     *
     * If a step was left mid-flight (Running/Retrying) by a crashed worker it is
     * reclaimed atomically, so a worker restart never duplicates completed work.
     *
     * @param  null|callable(array<string, mixed>): void  $listener
     */
    public function executeNextStep(Task $task, ?callable $listener = null): bool
    {
        if ($task->status->isTerminal()) {
            return false;
        }

        if (! in_array($task->status, [TaskStatus::Running, TaskStatus::Retrying], true)) {
            $task->markRunning();
        } else {
            $task->touchHeartbeat();
        }

        $step = $this->nextUnfinishedStep($task);

        if ($step === null) {
            return false;
        }

        // A leftover Running/Retrying step means the previous worker died
        // mid-step. This worker owns execution now, so it reclaims the step.
        if (in_array($step->status, [TaskStepStatus::Running, TaskStepStatus::Retrying], true)) {
            $step->forceFill(['status' => TaskStepStatus::Pending])->save();
        }

        $this->runStep($task, $step, $listener);
        $task->refresh();

        return ! $task->status->isTerminal() && $task->hasUnfinishedSteps();
    }

    /**
     * The next step that still requires execution (pending) or that a crashed
     * worker left unfinished (running/retrying).
     */
    public function nextUnfinishedStep(Task $task): ?TaskStep
    {
        return $task->steps()
            ->whereIn('status', [
                TaskStepStatus::Pending,
                TaskStepStatus::Running,
                TaskStepStatus::Retrying,
            ])
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * Resume a task from its latest checkpoint.
     */
    public function resume(Task $task, ?callable $listener = null): TaskResult
    {
        // Determine where we left off.
        $checkpoint = $task->latestCheckpoint;

        if ($checkpoint !== null) {
            $task->current_step = $checkpoint->current_step;
            $task->update(['current_step' => $checkpoint->current_step]);
        }

        if ($task->status === TaskStatus::Waiting) {
            $task->clearWaiting();
        }

        $this->notify($listener, [
            'type' => 'status',
            'status' => 'resuming',
            'label' => 'Resuming from checkpoint at step '.($checkpoint?->current_step ?? 0),
        ]);

        return $this->execute($task, $listener);
    }

    /**
     * Re-position a task for queue-driven execution without running the loop.
     * Used by the HTTP layer and the supervisor; the work itself happens in
     * ExecuteTaskStepJob after this returns.
     */
    public function prepareResume(Task $task): Task
    {
        $checkpoint = $task->latestCheckpoint;

        if ($checkpoint !== null) {
            $task->current_step = $checkpoint->current_step;
            $task->update(['current_step' => $checkpoint->current_step]);
        }

        if ($task->status === TaskStatus::Waiting) {
            $task->clearWaiting();
        }

        $task->markRunning();

        return $task->refresh();
    }

    // -------------------------------------------------------------------------
    // Step execution
    // -------------------------------------------------------------------------

    private function runStep(Task $task, TaskStep $step, ?callable $listener): void
    {
        if (! $step->dependenciesMet()) {
            $step->transitionTo(TaskStepStatus::Skipped);

            return;
        }

        $step->markRunning();
        $task->advanceStep();
        $task->update(['last_action' => $step->description]);
        event(new TaskStepStarted($task, $step->sort_order));
        $this->notify($listener, ['type' => 'step', 'step' => $step->sort_order, 'description' => $step->description]);

        // Retry loop for this step (transient failures retried with escalation).
        $attempts = 0;
        $maxAttempts = $step->max_attempts;

        while (true) {
            // Reset the transient retrying markers before another attempt so the
            // state machine transitions (Running -> Retrying) stay valid.
            if ($step->status === TaskStepStatus::Retrying) {
                $step->transitionTo(TaskStepStatus::Running);
            }

            if ($task->status === TaskStatus::Retrying) {
                $task->forceFill(['status' => TaskStatus::Running])->save();
            }

            // Escalate to a stronger model after repeated retries of the same step.
            if ($attempts >= 2) {
                $this->escalateModel($task, $step);
            }

            $this->attemptStep($task, $step, $listener);
            $task->refresh();
            $step->refresh();

            if ($step->status === TaskStepStatus::Completed) {
                $this->recordStepCheckpoint($task, $step);

                return;
            }

            if ($step->status === TaskStepStatus::Failed) {
                return; // handleStepFailure already failed the task / escalated
            }

            // Step is in Retrying state — loop again.
            if (++$attempts >= $maxAttempts) {
                $error = (string) ($step->error ?: 'Step exceeded retry limit.');
                $failureType = $step->failure_type ?? TaskFailureType::LogicalError;
                $step->transitionTo(TaskStepStatus::Failed);
                $this->fail($task, $error, $failureType, $listener);

                return;
            }
        }
    }

    private function recordStepCheckpoint(Task $task, TaskStep $step): void
    {
        $task->update(['last_result' => $step->result ?? '']);
        $task->createCheckpoint(
            $step->description,
            $step->result ?? '',
            "Completed step {$step->sort_order}: {$step->description}",
            [['step' => $step->sort_order, 'output' => substr((string) $step->result, 0, 200)]],
            $step->sort_order,
        );
        event(new TaskCheckpointCreated($task));
    }

    /**
     * Dispatch the step prompt to the agent. Uses the task/step model.
     *
     * @return array{output: ?string, error: ?string}
     */
    private function dispatchStepPrompt(Task $task, TaskStep $step): array
    {
        $prompt = $step->prompt ?: $step->description;

        if ($prompt === '') {
            return ['output' => '', 'error' => 'Step has no description.'];
        }

        $conversation = $task->conversation;

        if ($conversation === null) {
            $conversation = Conversation::create([
                'title' => 'Task: '.str()->limit($task->goal, 60),
                // Propagate the routed model. Without this the conversation falls
                // back to the conversations.model column default, which silently
                // bypasses the complexity router and escalation for every step.
                'model' => $task->model ?: $this->router->modelForTask($task),
            ]);
            $task->update(['conversation_id' => $conversation->id]);
        }

        $fullPrompt = $this->buildStepPrompt($task, $step);

        try {
            $message = $this->agent->run($conversation, $fullPrompt, "conversation.{$conversation->id}");

            $content = (string) $message->content;

            if (str_starts_with($content, 'LaraClaw could not complete')) {
                return ['output' => '', 'error' => $content];
            }

            return ['output' => $content, 'error' => null];
        } catch (Throwable $exception) {
            return ['output' => '', 'error' => $exception->getMessage()];
        }
    }

    private function attemptStep(Task $task, TaskStep $step, ?callable $listener): void
    {
        $result = $this->dispatchStepPrompt($task, $step);

        if ($result['error'] === null) {
            $step->markCompleted($result['output'] ?? '');

            return;
        }

        $failureType = $step->failure_type ?? $this->classifier->classify($result['error'], $step->prompt ?? null);
        $error = $result['error'];

        $task->update([
            'last_error' => $error,
            'last_heartbeat_at' => now(),
        ]);

        // Pathological loop detection.
        $loop = $this->loopDetector->detectLoop($task, (int) config('agent.task.loop_threshold', 4));

        if ($loop !== null) {
            $step->markFailed($error, TaskFailureType::Unrecoverable);
            $this->fail($task, $loop, TaskFailureType::Unrecoverable, $listener);

            return;
        }

        if ($failureType->isRetryable() && $step->canRetry()) {
            $step->markRetrying();
            $step->update(['error' => $error, 'failure_type' => $failureType]);
            $task->markRetrying();
            event(new TaskRetrying($task, $step->attempts, $error));
            $this->notify($listener, ['type' => 'retry', 'attempt' => $step->attempts, 'error' => $error]);

            return;
        }

        // Unrecoverable or out of retries.
        $step->markFailed($error, $failureType);
        $this->fail($task, $error, $failureType, $listener);
    }

    /**
     * Escalate the task's model to a stronger tier when repeated retries suggest
     * the current model is insufficient for the step.
     */
    private function escalateModel(Task $task, TaskStep $step): void
    {
        $fromModel = $task->model ?: $this->router->modelForTask($task);
        $escalation = $this->router->escalateForFailure($task, "Step {$step->sort_order} retried {$step->attempts} times");

        if ($escalation['escalated'] && $escalation['model'] !== $fromModel) {
            $task->update(['model' => $escalation['model']]);
            event(new TaskEscalated($task, $fromModel, $escalation['model'], $escalation['reason']));
            $this->notify(null, [
                'type' => 'escalation',
                'from' => $fromModel,
                'to' => $escalation['model'],
                'reason' => $escalation['reason'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Finalization & verification
    // -------------------------------------------------------------------------

    /**
     * Run the completion gate: verify, then mark completed only when verification
     * passes and no blockers remain. On failure, add repair steps and return the
     * task in Running state so the caller (job or loop) re-executes them.
     *
     * The model's own claim of completion is never trusted — only the verifier's
     * structured result is.
     *
     * @param  null|callable(array<string, mixed>): void  $listener
     */
    public function finalizeTask(Task $task, ?callable $listener = null): TaskResult
    {
        event(new TaskVerificationStarted($task));
        $this->notify($listener, ['type' => 'status', 'status' => 'verifying', 'label' => 'Verifying completion']);

        if ($task->status !== TaskStatus::Verifying) {
            $task->transitionTo(TaskStatus::Verifying);
        }

        $result = $this->verifier->verify($task);
        $this->verifier->persistResult($task, $result);

        if ($result['status'] === 'passed' && $this->completionBlockers($task) === []) {
            $task->markCompleted();
            event(new TaskVerificationPassed($task, $result));
            event(new TaskCompleted($task));
            $this->notify($listener, ['type' => 'done', 'status' => 'completed', 'result' => $result]);

            return TaskResult::fromTask($task);
        }

        event(new TaskVerificationFailed($task, $result['failed_checks'], (string) $result['reason']));

        $reason = (string) ($result['reason'] ?? implode('; ', $this->completionBlockers($task)));

        // Retryable verification failure: add repair steps addressing the failed
        // checks and put the task back into Running for another round.
        if ($task->attempts < $task->max_attempts) {
            $task->increment('attempts');
            $this->addRepairSteps($task, $result['failed_checks'] ?: ['Task completion']);
            $task->forceFill(['status' => TaskStatus::Running])->save();
            event(new TaskRetrying($task, $task->attempts, $reason));
            $this->notify($listener, ['type' => 'status', 'status' => 'repairing', 'label' => 'Verification failed; repairing']);

            return TaskResult::fromTask($task);
        }

        return $this->fail($task, $reason, TaskFailureType::ValidationError, $listener);
    }

    /**
     * Central completion gate. Returns the reasons a task cannot (yet) be
     * marked completed. An empty result means completion is allowed.
     *
     * @return array<int, string>
     */
    public function completionBlockers(Task $task): array
    {
        $blockers = [];

        if ($task->waiting_since !== null) {
            $blockers[] = 'Task is waiting: '.($task->waiting_reason ?? 'awaiting a condition').'.';
        }

        if ($task->hasUnfinishedSteps()) {
            $blockers[] = 'Not all steps are completed.';
        }

        return $blockers;
    }

    /**
     * Append repair steps addressing the failed verification checks.
     *
     * @param  array<int, string>  $failedChecks
     */
    private function addRepairSteps(Task $task, array $failedChecks): void
    {
        $nextOrder = ($task->steps()->max('sort_order') ?? 0) + 1;

        foreach ($failedChecks as $index => $check) {
            $task->steps()->create([
                'sort_order' => $nextOrder + $index,
                'description' => "Repair: {$check}",
                'prompt' => "The verification check '{$check}' failed for the task. Inspect the current state, diagnose why it failed, fix it, and verify the fix. Check: {$check}",
                'max_attempts' => (int) config('agent.task.max_step_attempts', 3),
            ]);
        }

        $task->update(['total_steps' => $task->steps()->count()]);
    }

    // -------------------------------------------------------------------------
    // Failure handling
    // -------------------------------------------------------------------------

    private function fail(Task $task, string $reason, TaskFailureType $failureType, ?callable $listener): TaskResult
    {
        $task->markFailed($reason, $failureType);
        event(new TaskFailed($task, $reason));
        $this->notify($listener, ['type' => 'error', 'error' => $reason]);

        return TaskResult::fromTask($task);
    }

    // -------------------------------------------------------------------------
    // Control (pause / resume / cancel / restart)
    // -------------------------------------------------------------------------

    public function pause(Task $task): void
    {
        if (in_array($task->status, [TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed], true)) {
            throw new InvalidArgumentException(
                "Cannot pause a task in the [{$task->status->label()}] state."
            );
        }

        $task->forceFill(['status' => TaskStatus::Paused])->save();
        $task->touchHeartbeat();
    }

    public function cancel(Task $task, bool $safe = true): void
    {
        $task->markCancelled();
        event(new TaskCancelled($task));
    }

    /**
     * Cooperative wait: the task legitimately cannot continue right now (user
     * approval, external service, scheduled time). It stays non-terminal and
     * resumes automatically once the resume condition is satisfied.
     *
     * @param  array{type: string, after_minutes?: int}|null  $condition
     */
    public function wait(Task $task, string $reason, ?array $condition = null): void
    {
        $task->markWaiting($reason, $condition);
    }

    /**
     * Whether a waiting task's resume condition is satisfied.
     *
     * Support values:
     *   ['type' => 'time', 'after_minutes' => N]  — N minutes after waiting_since
     *   ['type' => 'user']                        — requires manual resume
     *   null / any other                         — requires manual resume
     */
    public function resumeConditionMet(Task $task): bool
    {
        $condition = $task->resume_condition;

        if (! is_array($condition) || ($condition['type'] ?? null) === null) {
            return false;
        }

        if (($condition['type'] ?? '') !== 'time') {
            return false;
        }

        $afterMinutes = (int) ($condition['after_minutes'] ?? 0);
        $since = $task->waiting_since;

        if ($since === null) {
            return false;
        }

        return now()->gte($since->copy()->addMinutes($afterMinutes));
    }

    /**
     * Clear the waiting state and mark the task running for the next job.
     */
    public function resumeFromWaiting(Task $task): Task
    {
        $task->clearWaiting();
        $task->markRunning();

        return $task->refresh();
    }

    public function restartFailedStep(Task $task, int $sortOrder): TaskStep
    {
        $step = $task->steps()->where('sort_order', $sortOrder)->first();

        if ($step === null) {
            throw new InvalidArgumentException("Step {$sortOrder} not found.");
        }

        DB::transaction(function () use ($step): void {
            $step->forceFill([
                'status' => TaskStepStatus::Pending,
                'attempts' => 0,
                'error' => null,
                'result' => null,
                'failure_type' => null,
            ])->save();

            if ($step->task->status === TaskStatus::Failed) {
                $step->task->forceFill([
                    'status' => TaskStatus::Running,
                    'failure_reason' => null,
                    'failure_type' => null,
                    'completed_at' => null,
                ])->save();
            }
        });

        return $step;
    }

    // -------------------------------------------------------------------------
    // Recovery
    // -------------------------------------------------------------------------

    /**
     * Recover a stalled task. Determines the last successful step, whether the
     * current step completed, and continues from the correct point.
     *
     * @return array{status: string, checkpoint?: array<string, mixed>, resumed_from?: int}
     */
    public function recover(Task $task, ?callable $listener = null): array
    {
        $checkpoint = $task->latestCheckpoint;

        $info = [
            'status' => 'recovering',
            'resumed_from' => $task->current_step,
        ];

        if ($checkpoint !== null) {
            $info['checkpoint'] = [
                'id' => $checkpoint->id,
                'step_sort_order' => $checkpoint->step_sort_order,
                'action_taken' => $checkpoint->action_taken,
                'current_step' => $checkpoint->current_step,
                'created_at' => $checkpoint->created_at->toISOString(),
            ];

            $resumeFrom = $checkpoint->step_sort_order ?? $checkpoint->current_step;
            $info['resumed_from'] = $resumeFrom;

            $task->current_step = $resumeFrom;
            $task->update(['current_step' => $resumeFrom, 'status' => TaskStatus::Running]);
        } else {
            // No checkpoint: restart from step 0 but avoid destructive ops via plan.
            $task->update(['status' => TaskStatus::Running]);
        }

        if ($task->status === TaskStatus::Waiting) {
            $task->clearWaiting();
        }

        $this->notify($listener, [
            'type' => 'status',
            'status' => 'recovering',
            'label' => 'Recovering at step '.($info['resumed_from'] ?? 0),
        ]);

        return $info;
    }

    // -------------------------------------------------------------------------
    // Prompt building
    // -------------------------------------------------------------------------

    private function buildPlanningPrompt(Task $task): string
    {
        $specialist = $this->specialists->bySlug('planner');

        return <<<PROMPT
You are the planning specialist.
Specialist guidance: {$specialist?->systemPrompt}

{$this->context->buildSummary($task)}

GOAL: {$task->goal}

Produce a numbered list of concrete, atomic steps needed to accomplish this goal
using the available tools (file, shell, web, browser, memory, mission, opencode, etc.).
Each step should be one line in the format:

STEP: <description>
PROMPT_FOR_STEP: <instruction to hand to the executor for this step>

Only include steps that are strictly necessary. Keep them small and verifiable.
Do NOT write code, do NOT execute anything — planning only.

Return nothing else except your plan.
PROMPT;
    }

    private function buildStepPrompt(Task $task, TaskStep $step): string
    {
        $goal = $task->goal;
        $instruction = $step->prompt ?: $step->description;
        $status = "You are executing one step of the task. Task status: {$task->status->label()}. Current step {$step->sort_order} of {$task->total_steps}.";
        $specialist = $this->specialists->resolve($task, $step);

        return <<<PROMPT
{$status}
{$this->specialists->buildPromptContext($specialist, $task)}
{$this->context->buildCtxBlock($task)}

TASK GOAL: {$goal}

TODAY'S STEP [{$step->sort_order}/{$task->total_steps}]:
{$instruction}

Execute this step using your tools. Report clearly what you did and the result.
Do not mark the overall task complete. Respect the environment and safety checks.
PROMPT;
    }

    private function parsePlan(string $response): array
    {
        $steps = [];
        preg_match_all('/STEP:\s*(.+)/i', $response, $m);

        foreach ($m[1] ?? [] as $description) {
            $steps[] = ['description' => trim($description)];
        }

        return $steps;
    }

    private function notify(?callable $listener, array $payload): void
    {
        if ($listener !== null) {
            $listener($payload);
        }
    }
}
