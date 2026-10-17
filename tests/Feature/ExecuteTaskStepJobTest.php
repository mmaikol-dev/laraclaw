<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;
use App\Services\TaskEngine\TaskEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ExecuteTaskStepJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_its_timeout_follows_the_step_timeout_config(): void
    {
        config(['agent.task.step_timeout_seconds' => 1234]);

        $job = new ExecuteTaskStepJob(1);

        $this->assertSame(1234, $job->timeout);
    }

    public function test_it_returns_early_for_a_terminal_task(): void
    {
        Queue::fake();

        $task = Task::factory()->completed()->create();

        $engine = Mockery::mock(TaskEngine::class);
        $engine->shouldNotReceive('executeNextStep');

        (new ExecuteTaskStepJob($task->id))->handle($engine);

        Queue::assertNothingPushed();
    }

    public function test_it_returns_early_for_a_paused_task(): void
    {
        Queue::fake();

        $task = Task::factory()->create([
            'status' => TaskStatus::Paused,
            'last_heartbeat_at' => now(),
        ]);

        $engine = Mockery::mock(TaskEngine::class);
        $engine->shouldNotReceive('executeNextStep');

        (new ExecuteTaskStepJob($task->id))->handle($engine);

        Queue::assertNothingPushed();
    }

    public function test_it_does_not_execute_or_requeue_when_another_worker_holds_the_lock(): void
    {
        Queue::fake();

        $task = Task::factory()->running()->create();

        $held = Cache::lock('task-execute:'.$task->id, 300);
        $this->assertTrue($held->get());

        try {
            $engine = Mockery::mock(TaskEngine::class);
            $engine->shouldNotReceive('executeNextStep');

            (new ExecuteTaskStepJob($task->id))->handle($engine);

            Queue::assertNothingPushed();
        } finally {
            $held->release();
        }
    }
}
