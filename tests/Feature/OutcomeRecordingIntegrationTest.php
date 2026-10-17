<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AgentRunOutcome;
use App\Models\Message;
use App\Models\Task;
use App\Services\Agent\AgentService;
use App\Services\TaskEngine\TaskEngine;
use App\Services\TaskEngine\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests prove OutcomeRecorder behaves correctly; these prove the task
 * engine actually reaches it. Without this the recorder could sit wired to
 * nothing and every suite would still be green.
 */
class OutcomeRecordingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Mockery\MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Mockery::mock(AgentService::class);
        $this->agent->shouldReceive('run')->andReturnUsing(function (): Message {
            $message = new Message;
            $message->content = 'Did the work.';

            return $message;
        });
        $this->agent->shouldReceive('chatRaw')->andReturn(
            "STEP: Research\nSTEP: Fix\nSTEP: Confirm"
        );
        $this->app->instance(AgentService::class, $this->agent);
    }

    public function test_a_completed_task_records_a_verified_outcome(): void
    {
        $this->mockVerifier('passed');
        $task = Task::factory()->create(['goal' => 'Add rate limiting']);

        $this->runTask($task);

        $outcome = AgentRunOutcome::query()->where('subject_id', $task->id)->sole();

        $this->assertSame(AgentRunOutcome::SOURCE_TASK, $outcome->source);
        $this->assertSame(AgentRunOutcome::STATUS_COMPLETED, $outcome->status);
        $this->assertSame(AgentRunOutcome::VERDICT_PASSED, $outcome->verdict);
        $this->assertSame('verifier', $outcome->verdict_source);
        $this->assertSame('Add rate limiting', $outcome->intent);
    }

    public function test_a_failed_task_records_why_it_failed(): void
    {
        $this->mockVerifier('failed');
        $task = Task::factory()->create([
            'goal' => 'Fix the login bug',
            'max_attempts' => 1,
        ]);

        $this->runTask($task);

        $outcome = AgentRunOutcome::query()->where('subject_id', $task->id)->sole();

        $this->assertSame(AgentRunOutcome::STATUS_FAILED, $outcome->status);
        $this->assertSame(AgentRunOutcome::VERDICT_FAILED, $outcome->verdict);
        $this->assertStringContainsString('Tests pass', (string) $outcome->failure_reason);
    }

    public function test_retries_produce_one_terminal_outcome_not_one_per_attempt(): void
    {
        $this->mockVerifier('failed');
        $task = Task::factory()->create([
            'goal' => 'Fix the login bug',
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->runTask($task);

        // A verification failure that never passes is non-retryable: the task is
        // parked for human input, but the run still records exactly one outcome.
        $this->assertSame(TaskStatus::Waiting, $task->fresh()->status);
        $this->assertTrue((bool) $task->fresh()->needs_human_input);

        $outcomes = AgentRunOutcome::query()->where('subject_id', $task->id)->get();
        $this->assertCount(1, $outcomes);
        $this->assertSame(AgentRunOutcome::VERDICT_FAILED, $outcomes->first()->verdict);
    }

    public function test_the_recorded_outcome_names_the_model_that_actually_ran(): void
    {
        $this->mockVerifier('passed');
        $task = Task::factory()->create(['goal' => 'Add rate limiting', 'model' => null]);

        $this->runTask($task);

        $outcome = AgentRunOutcome::query()->where('subject_id', $task->id)->sole();

        $this->assertNotNull($task->fresh()->model, 'Planning must persist the routed model.');
        $this->assertSame($task->fresh()->model, $outcome->model);
    }

    public function test_only_one_outcome_is_recorded_per_task(): void
    {
        $this->mockVerifier('passed');
        $task = Task::factory()->create(['goal' => 'Add rate limiting']);

        $this->runTask($task);

        $this->assertSame(1, AgentRunOutcome::query()->where('subject_id', $task->id)->count());
    }

    private function mockVerifier(string $status): void
    {
        $verifier = Mockery::mock(VerificationService::class);
        $verifier->shouldReceive('verify')->andReturn([
            'status' => $status,
            'checks' => [['name' => 'Tests', 'status' => $status]],
            'failed_checks' => $status === 'passed' ? [] : ['Tests pass'],
            'reason' => $status === 'passed' ? null : 'Failed checks: Tests pass',
        ]);
        $verifier->shouldReceive('persistResult')->andReturnNull();

        $this->app->instance(VerificationService::class, $verifier);
    }

    private function runTask(Task $task): void
    {
        $engine = $this->app->make(TaskEngine::class);
        $engine->plan($task);
        $engine->execute($task->fresh());
    }
}
