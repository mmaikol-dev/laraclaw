<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Events\TaskEscalated;
use App\Models\Message;
use App\Models\Task;
use App\Services\Agent\AgentService;
use App\Services\TaskEngine\TaskEngine;
use App\Services\TaskEngine\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class TaskEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.models' => [
                'simple' => 'simple:v1',
                'coding' => 'coding:v1',
                'reasoning' => 'reasoning:v1',
                'review' => 'review:v1',
                'complex' => 'complex:v1',
            ],
            'ollama.agent_model' => 'default:v1',
        ]);
    }

    private function failableEngine(): TaskEngine
    {
        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('run')->andReturnUsing(function (): Message {
            $message = new Message;
            $message->content = 'LaraClaw could not complete this step because the shell tool timed out.';

            return $message;
        });
        $agent->shouldReceive('chatRaw')->andReturn('STEP: Do the thing');
        $this->app->instance(AgentService::class, $agent);

        $verifier = Mockery::mock(VerificationService::class);
        $verifier->shouldReceive('verify')->andReturn([
            'status' => 'passed',
            'checks' => [['name' => 'Tests', 'status' => 'passed']],
            'failed_checks' => [],
            'reason' => null,
        ]);
        $verifier->shouldReceive('persistResult')->andReturnNull();
        $this->app->instance(VerificationService::class, $verifier);

        return $this->app->make(TaskEngine::class);
    }

    private function failableStep(Task $task): void
    {
        $task->steps()->create([
            'sort_order' => 1,
            'description' => 'Do the thing',
            'prompt' => 'Do the thing',
            'max_attempts' => 3,
        ]);
        $task->update(['total_steps' => 1, 'plan' => json_encode([['order' => 1, 'description' => 'Do the thing']])]);
    }

    public function test_repeated_step_failures_escalate_the_model(): void
    {
        Event::fake([TaskEscalated::class]);

        $engine = $this->failableEngine();

        $task = Task::factory()->create([
            'goal' => 'Implement the feature',
            'complexity_level' => 'simple',
            'model' => 'simple:v1',
        ]);

        $this->failableStep($task);

        $result = $engine->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);

        // The model should have been escalated away from the original simple tier.
        $this->assertNotSame('simple:v1', $task->model);
        $this->assertTrue(in_array($task->model, ['review:v1', 'reasoning:v1', 'complex:v1'], true));

        Event::assertDispatched(TaskEscalated::class);
    }

    public function test_single_failure_does_not_escalate_the_model(): void
    {
        Event::fake([TaskEscalated::class]);

        // An agent that fails once then succeeds.
        $agent = Mockery::mock(AgentService::class);
        $calls = 0;
        $agent->shouldReceive('run')->andReturnUsing(function () use (&$calls): Message {
            $message = new Message;
            $calls++;

            if ($calls === 1) {
                $message->content = 'LaraClaw could not complete this step because the shell tool timed out.';

                return $message;
            }

            $message->content = 'Done.';

            return $message;
        });
        $agent->shouldReceive('chatRaw')->andReturn('STEP: Do the thing');
        $this->app->instance(AgentService::class, $agent);

        $verifier = Mockery::mock(VerificationService::class);
        $verifier->shouldReceive('verify')->andReturn([
            'status' => 'passed',
            'checks' => [['name' => 'Tests', 'status' => 'passed']],
            'failed_checks' => [],
            'reason' => null,
        ]);
        $verifier->shouldReceive('persistResult')->andReturnNull();
        $this->app->instance(VerificationService::class, $verifier);

        $task = Task::factory()->create([
            'goal' => 'Implement the feature',
            'complexity_level' => 'simple',
            'model' => 'simple:v1',
        ]);

        $this->failableStep($task);

        $result = $this->app->make(TaskEngine::class)->execute($task);
        $task->refresh();

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame('simple:v1', $task->model);
        Event::assertNotDispatched(TaskEscalated::class);
    }
}
