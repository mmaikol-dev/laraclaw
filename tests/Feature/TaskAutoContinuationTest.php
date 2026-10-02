<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Message;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\Agent\AgentService;
use App\Services\TaskEngine\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Proves the core fix: a model response ending is treated as ONE step, not the
 * end of the task. Execution continues automatically via queued jobs until a
 * genuine terminal state is reached — the user never has to send "continue".
 */
class TaskAutoContinuationTest extends TestCase
{
    use RefreshDatabase;

    private Mockery\MockInterface $agent;

    private Mockery\MockInterface $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Mockery::mock(AgentService::class);
        $this->agent->shouldReceive('run')->andReturnUsing(
            fn () => $this->fakeMessage('Executed step.')
        );
        $this->agent->shouldReceive('chatRaw')->andReturn(
            "STEP: Execute step\nSTEP: Execute next step"
        );
        $this->app->instance(AgentService::class, $this->agent);

        $this->verifier = Mockery::mock(VerificationService::class);
        $this->verifier->shouldReceive('persistResult')->andReturnUsing(function (Task $task, array $result): void {
            $task->update([
                'verification_status' => $result['status'],
                'verification_results' => $result,
                'last_verified_at' => now(),
            ]);
        });
        $this->app->instance(VerificationService::class, $this->verifier);

        // phpunit.xml uses QUEUE_CONNECTION=sync, so dispatched jobs run inline
        // and the auto-continuation chain is exercised end to end.
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function fakeMessage(string $content): Message
    {
        $message = new Message;
        $message->content = $content;

        return $message;
    }

    /**
     * @return array<int, TaskStep>
     */
    private function addSteps(Task $task, int $count): array
    {
        $steps = [];

        for ($i = 1; $i <= $count; $i++) {
            $steps[] = TaskStep::factory()->create([
                'task_id' => $task->id,
                'sort_order' => $i,
                'description' => "Step {$i}",
                'prompt' => "Execute step {$i}",
            ]);
        }

        return $steps;
    }

    private function verifierPassesAlways(): void
    {
        $this->verifier->shouldReceive('verify')->andReturn([
            'status' => 'passed',
            'checks' => [['name' => 'Tests', 'status' => 'passed']],
            'failed_checks' => [],
            'reason' => null,
        ]);
    }

    public function test_task_with_ten_steps_completes_automatically_without_continue(): void
    {
        $this->verifierPassesAlways();

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $this->addSteps($task, 10);

        // The model finishes one generation; the user sends NOTHING else.
        ExecuteTaskStepJob::dispatch($task->id);

        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(10, $task->steps()->count());
        $this->assertSame(10, $task->steps()->where('status', TaskStepStatus::Completed)->count());
        $this->assertSame(10, $task->checkpoints()->count());
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($task->verification_results);
    }

    public function test_worker_restart_resumes_from_leftover_step_without_duplicating_work(): void
    {
        $this->verifierPassesAlways();

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $steps = $this->addSteps($task, 10);

        // Steps 1-4 genuinely completed and checkpointed.
        foreach ($steps as $i => $step) {
            if ($i < 4) {
                $step->update(['status' => TaskStepStatus::Completed, 'result' => "result-{$step->sort_order}"]);
            }
        }

        $task->update(['current_step' => 5]);
        $task->createCheckpoint('Step 4', 'result-4', 'Completed step 4', [], 4);

        // Step 5 was left Running when the worker died.
        $steps[4]->update(['status' => TaskStepStatus::Running, 'started_at' => now()]);
        $task->markRunning();

        // A new "worker" picks the task up.
        ExecuteTaskStepJob::dispatch($task->id);

        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(TaskStepStatus::Completed, $steps[4]->refresh()->status);
        $this->assertSame(10, $task->steps()->where('status', TaskStepStatus::Completed)->count());
        // Completed work is never duplicated: step 1 result is untouched.
        $this->assertSame('result-1', $task->steps()->where('sort_order', 1)->first()->result);
    }

    public function test_model_claiming_done_does_not_stop_the_task(): void
    {
        $this->verifierPassesAlways();

        // The model repeatedly claims it is finished, but steps remain.
        $this->agent->shouldReceive('run')->andReturnUsing(
            fn () => $this->fakeMessage('I have completed everything. Nothing left to do.')
        );

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $this->addSteps($task, 5);

        ExecuteTaskStepJob::dispatch($task->id);

        $task->refresh();

        // The task continued purely from persisted state, ignoring the claim.
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(5, $task->steps()->where('status', TaskStepStatus::Completed)->count());
        $this->assertSame(5, $task->checkpoints()->count());
    }

    public function test_failed_verification_repairs_reruns_verification_then_completes(): void
    {
        $this->verifier->shouldReceive('verify')->andReturnUsing(function (Task $task): array {
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

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $this->addSteps($task, 2);

        ExecuteTaskStepJob::dispatch($task->id);

        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertTrue($task->steps()->where('description', 'like', 'Repair:%')->count() > 0);
        $this->assertSame('passed', $task->verification_status);
    }

    public function test_verification_that_never_passes_never_completes_the_task(): void
    {
        $this->verifier->shouldReceive('verify')->andReturn([
            'status' => 'failed',
            'checks' => [['name' => 'Tests', 'status' => 'failed']],
            'failed_checks' => ['Tests pass'],
            'reason' => 'Failed checks: Tests pass',
        ]);

        $task = Task::factory()->create([
            'goal' => 'Apply to four jobs',
            'max_attempts' => 1,
        ]);
        $this->addSteps($task, 2);

        ExecuteTaskStepJob::dispatch($task->id);

        $task->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNotSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->failure_reason);
    }

    public function test_a_locked_task_is_not_executed_by_a_second_worker(): void
    {
        $this->verifierPassesAlways();

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $step = $this->addSteps($task, 1)[0];

        $lock = Cache::lock('task-execute:'.$task->id, 300);
        $this->assertTrue($lock->get());

        // A second worker attempts the task while it is locked.
        ExecuteTaskStepJob::dispatch($task->id);

        $this->assertSame(TaskStepStatus::Pending, $step->refresh()->status);
        $this->assertSame(TaskStatus::Pending, $task->fresh()->status);

        $lock->release();

        ExecuteTaskStepJob::dispatch($task->id);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_waiting_task_resumes_automatically_when_condition_is_met(): void
    {
        $this->verifierPassesAlways();

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $this->addSteps($task, 3);
        $task->markWaiting('Awaiting scheduled submission window', ['type' => 'time', 'after_minutes' => 5]);
        $task->update(['waiting_since' => now()->subMinutes(10)]);

        ExecuteTaskStepJob::dispatch($task->id);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_waiting_task_requiring_user_action_stays_waiting(): void
    {
        $this->verifierPassesAlways();

        $task = Task::factory()->create(['goal' => 'Apply to four jobs']);
        $step = $this->addSteps($task, 2)[0];
        $task->markWaiting('Awaiting user approval', ['type' => 'user']);

        ExecuteTaskStepJob::dispatch($task->id);

        $task->refresh();

        $this->assertSame(TaskStatus::Waiting, $task->status);
        $this->assertSame(TaskStepStatus::Pending, $step->refresh()->status);
    }
}
