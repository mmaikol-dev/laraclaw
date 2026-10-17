<?php

namespace Tests\Feature;

use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Models\AgentRoleProfile;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\Agent\AgentService;
use App\Services\Agent\OutcomeRecorder;
use App\Services\Agent\RoleProfileService;
use App\Services\Agent\ToolRegistry;
use App\Services\TaskEngine\FailureClassifier;
use App\Services\TaskEngine\LoopDetector;
use App\Services\TaskEngine\ModelRouter;
use App\Services\TaskEngine\SpecialistAgents;
use App\Services\TaskEngine\TaskContext;
use App\Services\TaskEngine\TaskEngine;
use App\Services\TaskEngine\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TaskEngineTest extends TestCase
{
    use RefreshDatabase;

    private TaskEngine $engine;

    private Mockery\MockInterface $agent;

    private int $planningCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Mockery::mock(AgentService::class);
        $this->agent->shouldReceive('run')->andReturnUsing(
            fn (Conversation $conversation, string $message) => $this->fakeMessage($message)
        );

        $this->agent->shouldReceive('chatRaw')->andReturnUsing(function (): string {
            $this->planningCalls++;

            return "STEP: Research the bug\nSTEP: Write a fix\nSTEP: Verify the fix";
        });

        $this->app->instance(AgentService::class, $this->agent);

        $verifier = Mockery::mock(VerificationService::class);
        $verifier->shouldReceive('verify')->andReturn([
            'status' => 'passed',
            'checks' => [['name' => 'Tests', 'status' => 'passed']],
            'failed_checks' => [],
            'reason' => null,
        ]);
        $verifier->shouldReceive('persistResult')->andReturnNull();
        $this->app->instance(VerificationService::class, $verifier);

        $this->engine = $this->app->make(TaskEngine::class);
    }

    private function fakeMessage(string $prompt): Message
    {
        $message = new Message;
        $message->content = match (true) {
            str_contains($prompt, 'Research') => 'Researched the bug; found root cause.',
            str_contains($prompt, 'Write a fix') => 'Wrote the fix to src/Foo.php.',
            str_contains($prompt, 'Verify') => 'Verification passed.',
            default => 'Done.',
        };

        return $message;
    }

    public function test_creating_a_task_uses_pending_status(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);

        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(0, $task->steps()->count());
    }

    public function test_task_can_be_planned_from_a_goal(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);

        $steps = $this->engine->plan($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Planning, $task->status);
        $this->assertCount(3, $steps);
        $this->assertSame(3, $task->total_steps);
        $this->assertDatabaseHas('task_steps', ['task_id' => $task->id, 'sort_order' => 1]);
        $this->assertNotNull($task->plan);
    }

    public function test_task_planning_dispatches_planning_prompt_to_the_agent(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);

        $this->engine->plan($task);

        $this->assertSame(3, $task->steps()->count());
        $this->assertSame(1, $this->planningCalls);
    }

    public function test_execute_runs_all_steps_and_completes_the_task(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $this->engine->plan($task);
        $task->refresh();

        $result = $this->engine->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(TaskStepStatus::Completed, $task->steps[0]->status);
        $this->assertSame(TaskStepStatus::Completed, $task->steps[1]->status);
        $this->assertSame(TaskStepStatus::Completed, $task->steps[2]->status);
    }

    public function test_execute_creates_checkpoints_after_each_step(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $this->engine->plan($task);
        $task->refresh();

        $this->engine->execute($task);

        $this->assertSame(3, $task->checkpoints()->count());
    }

    public function test_execute_fails_the_task_when_a_step_has_an_unrecoverable_error(): void
    {
        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('run')->andReturnUsing(function (): Message {
            $message = new Message;
            $message->content = 'LaraClaw could not complete this step because the tool returned an error.';

            return $message;
        });
        $agent->shouldReceive('chatRaw')->andReturn('STEP: Do the thing');

        $engine = new TaskEngine(
            $this->app->make(ToolRegistry::class),
            $this->app->make(ModelRouter::class),
            $this->app->make(FailureClassifier::class),
            $this->app->make(LoopDetector::class),
            $this->app->make(VerificationService::class),
            $agent,
            $this->app->make(SpecialistAgents::class),
            $this->app->make(TaskContext::class),
            new OutcomeRecorder,
            $this->app->make(RoleProfileService::class),
        );

        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $engine->plan($task);
        $task->refresh();

        $result = $engine->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNotNull($task->failure_reason);
    }

    public function test_execute_parks_the_task_when_a_step_fails_non_retryably(): void
    {
        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('run')->andReturnUsing(function (): Message {
            $message = new Message;
            $message->content = 'LaraClaw could not complete this step: permission denied.';

            return $message;
        });
        $agent->shouldReceive('chatRaw')->andReturn('STEP: Do the thing');

        $engine = new TaskEngine(
            $this->app->make(ToolRegistry::class),
            $this->app->make(ModelRouter::class),
            $this->app->make(FailureClassifier::class),
            $this->app->make(LoopDetector::class),
            $this->app->make(VerificationService::class),
            $agent,
            $this->app->make(SpecialistAgents::class),
            $this->app->make(TaskContext::class),
            new OutcomeRecorder,
            $this->app->make(RoleProfileService::class),
        );

        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $engine->plan($task);
        $task->refresh();

        $engine->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Waiting, $task->status);
        $this->assertTrue((bool) $task->needs_human_input);
        $this->assertSame(TaskFailureType::PermissionError, $task->failure_type);
        $this->assertSame('human_input', $task->resume_condition['type'] ?? null);
    }

    public function test_execute_parks_the_task_when_a_401_surfaces_mid_retry(): void
    {
        $attempt = 0;
        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('run')->andReturnUsing(function () use (&$attempt): Message {
            $message = new Message;
            $message->content = ++$attempt === 1
                ? 'LaraClaw could not complete this step: Ollama model overloaded.'
                : 'LaraClaw could not complete this step: Ollama chat stream failed with status 401.';

            return $message;
        });
        $agent->shouldReceive('chatRaw')->andReturn('STEP: Do the thing');

        $engine = new TaskEngine(
            $this->app->make(ToolRegistry::class),
            $this->app->make(ModelRouter::class),
            $this->app->make(FailureClassifier::class),
            $this->app->make(LoopDetector::class),
            $this->app->make(VerificationService::class),
            $agent,
            $this->app->make(SpecialistAgents::class),
            $this->app->make(TaskContext::class),
            new OutcomeRecorder,
            $this->app->make(RoleProfileService::class),
        );

        $task = Task::factory()->create(['goal' => 'Upload the clips to YouTube']);
        $engine->plan($task);
        $task->refresh();

        $engine->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Waiting, $task->status);
        $this->assertTrue((bool) $task->needs_human_input);
        $this->assertSame(TaskFailureType::PermissionError, $task->failure_type);
    }

    public function test_resume_continues_from_the_latest_checkpoint(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $this->engine->plan($task);
        $task->refresh();

        // Simulate an interruption: mark the task Running and create a checkpoint at step 1.
        $task->markRunning();
        $first = $task->steps()->where('sort_order', 1)->first();
        $first->markCompleted('Done with first step');
        $task->update(['current_step' => 1]);
        $task->createCheckpoint('First step', 'Done', 'Completed step 1', [], 1);

        $result = $this->engine->resume($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertTrue($task->steps[1]->status === TaskStepStatus::Completed);
        $this->assertTrue($task->steps[2]->status === TaskStepStatus::Completed);
        $this->assertGreaterThan(1, $task->checkpoints()->count());
    }

    public function test_pause_and_resume_workflow(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $this->engine->plan($task);
        $task->refresh();

        $this->engine->pause($task);
        $this->assertSame(TaskStatus::Paused, $task->fresh()->status);

        $result = $this->engine->resume($task);
        $this->assertSame(TaskStatus::Completed, $result->task->status);
    }

    public function test_cancel_marks_the_task_cancelled(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $this->engine->plan($task);
        $task->refresh();

        $this->engine->cancel($task);

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_completion_requires_verification_to_pass(): void
    {
        // First verify() call fails; after repair steps run, the second passes.
        $verifier = Mockery::mock(VerificationService::class);
        $verifier->shouldReceive('verify')->andReturnUsing(function (Task $task) {
            if ($task->steps()->where('description', 'like', 'Repair:%')->exists()) {
                return [
                    'status' => 'passed',
                    'checks' => [['name' => 'Tests', 'status' => 'passed']],
                    'failed_checks' => [],
                    'reason' => null,
                ];
            }

            return [
                'status' => 'failed',
                'checks' => [['name' => 'Tests', 'status' => 'failed']],
                'failed_checks' => ['Tests pass'],
                'reason' => 'Failed checks: Tests pass',
            ];
        });
        $verifier->shouldReceive('persistResult')->andReturnNull();

        $engine = new TaskEngine(
            $this->app->make(ToolRegistry::class),
            $this->app->make(ModelRouter::class),
            $this->app->make(FailureClassifier::class),
            $this->app->make(LoopDetector::class),
            $verifier,
            $this->agent,
            $this->app->make(SpecialistAgents::class),
            $this->app->make(TaskContext::class),
            new OutcomeRecorder,
            $this->app->make(RoleProfileService::class),
        );

        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $engine->plan($task);
        $task->refresh();

        $result = $engine->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertTrue($task->steps()->where('description', 'like', 'Repair:%')->count() > 0);
    }

    public function test_plan_uses_model_routing_for_the_agent(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the broken login']);
        $this->engine->plan($task);

        $this->assertSame(3, $task->steps()->count());
        $this->assertSame(1, $this->planningCalls);
    }

    public function test_plan_assigns_a_role_profile_to_steps_from_keywords(): void
    {
        $this->profile('devops_operator', 'DevOps Operator');
        $this->profile('qa_operator', 'QA Operator');
        $this->profile('laravel_architect', 'Laravel Architect');

        $task = Task::factory()->create(['goal' => 'Ship the full pipeline']);

        $this->engine->plan($task, [
            ['description' => 'Deploy the app to the staging server'],
            ['description' => 'Add a Laravel migration for orders'],
            ['description' => 'Write regression tests and verify coverage'],
        ]);
        $task->refresh();

        $roles = $task->steps()->with('roleProfile')->get()
            ->map(fn (TaskStep $step): ?string => $step->roleProfile?->slug)
            ->all();

        $this->assertSame(['devops_operator', 'laravel_architect', 'qa_operator'], $roles);
    }

    public function test_role_tagged_steps_run_in_dedicated_per_role_conversations(): void
    {
        $devops = $this->profile('devops_operator', 'DevOps Operator');
        $qa = $this->profile('qa_operator', 'QA Operator');

        $task = Task::factory()->create(['goal' => 'Set up deployment and verify it']);

        $this->engine->plan($task, [
            ['description' => 'Deploy the app to the server'],
            ['description' => 'Write regression tests and verify coverage'],
        ]);
        $task->refresh();

        $this->engine->execute($task);
        $task->refresh();

        $steps = $task->steps()->get();

        $this->assertNotNull($steps[0]->conversation_id);
        $this->assertNotNull($steps[1]->conversation_id);
        $this->assertNotSame($steps[0]->conversation_id, $steps[1]->conversation_id);

        $roleIds = Conversation::query()
            ->whereKey([$steps[0]->conversation_id, $steps[1]->conversation_id])
            ->pluck('role_profile_id')
            ->all();

        $this->assertEqualsCanonicalizing([$devops->id, $qa->id], $roleIds);
    }

    private function profile(string $slug, string $name): AgentRoleProfile
    {
        return AgentRoleProfile::query()->create([
            'slug' => $slug,
            'name' => $name,
            'is_active' => true,
        ]);
    }
}
