<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;
use App\Services\TaskEngine\TaskSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskSupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_recovers_stale_tasks_and_requeues_them(): void
    {
        $task = Task::factory()->stale()->create();

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertCount(1, $actions);
        $this->assertSame('recovered', $actions[0]['action']);
        $this->assertSame($task->id, $actions[0]['task_id']);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);

        Queue::assertPushed(ExecuteTaskStepJob::class, fn (ExecuteTaskStepJob $job): bool => $job->taskId === $task->id);
    }

    public function test_it_ignores_tasks_with_fresh_heartbeats(): void
    {
        Task::factory()->running()->create();

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame([], $actions);
    }

    public function test_it_never_auto_resumes_a_paused_task(): void
    {
        $task = Task::factory()->create([
            'status' => TaskStatus::Paused,
            'last_heartbeat_at' => now()->subMinutes(20),
            'started_at' => now()->subHour(),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame([], $actions);
        $this->assertSame(TaskStatus::Paused, $task->fresh()->status);
    }

    public function test_it_resumes_waiting_tasks_when_the_condition_is_met(): void
    {
        $ready = Task::factory()->create([
            'status' => TaskStatus::Waiting,
            'waiting_since' => now()->subMinutes(10),
            'waiting_reason' => 'Awaiting scheduled time',
            'resume_condition' => ['type' => 'time', 'after_minutes' => 5],
            'last_heartbeat_at' => now(),
        ]);

        $notReady = Task::factory()->create([
            'status' => TaskStatus::Waiting,
            'waiting_since' => now(),
            'waiting_reason' => 'Awaiting user approval',
            'resume_condition' => ['type' => 'user'],
            'last_heartbeat_at' => now(),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame(TaskStatus::Running, $ready->fresh()->status);
        $this->assertSame(TaskStatus::Waiting, $notReady->fresh()->status);
        $this->assertTrue(collect($actions)->contains('action', 'resumed'));
        Queue::assertPushed(ExecuteTaskStepJob::class, 1);
    }

    public function test_it_fails_tasks_with_excessive_attempts(): void
    {
        $task = Task::factory()->create([
            'status' => TaskStatus::Running,
            'attempts' => 5,
            'max_attempts' => 5,
            'last_heartbeat_at' => now(),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame('failed', $actions[0]['action']);
        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
        $this->assertStringContainsString('maximum attempts', $task->fresh()->failure_reason);
    }

    public function test_it_fails_tasks_that_exceed_max_execution_time(): void
    {
        config(['agent.task.max_execution_minutes' => 1]);

        $task = Task::factory()->create([
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinutes(30),
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertNotEmpty($actions);
        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
    }

    public function test_recovering_a_task_with_no_checkpoint_returns_status(): void
    {
        $task = Task::factory()->stale()->create();

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame('recovered', $actions[0]['action']);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }
}
