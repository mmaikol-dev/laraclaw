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

    public function test_it_recovers_a_task_that_stops_heartbeating_instead_of_failing_it(): void
    {
        $task = Task::factory()->create([
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinutes(30),
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertNotEmpty($actions);
        $this->assertSame('recovered', $actions[0]['action']);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
        $this->assertSame(1, $task->fresh()->auto_recoveries);
    }

    public function test_it_does_not_recover_a_task_whose_step_is_still_running(): void
    {
        $task = Task::factory()->create([
            'status' => TaskStatus::Running,
            'started_at' => now()->subHour(),
            // Heartbeat is older than the stale grace period, but the step that
            // owns the task is still executing inside its timeout window.
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => 'running',
            'description' => 'Long OpenCode turn',
            'started_at' => now()->subMinutes(5),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame([], $actions);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
        $this->assertTrue($task->fresh()->last_heartbeat_at->gt(now()->subMinute()));
    }

    public function test_it_recovers_a_task_whose_running_step_exceeded_its_timeout(): void
    {
        config(['agent.task.step_timeout_seconds' => 60]);

        $task = Task::factory()->create([
            'status' => TaskStatus::Running,
            'started_at' => now()->subHour(),
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => 'running',
            'description' => 'Dead OpenCode turn',
            'started_at' => now()->subMinutes(30),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame('recovered', $actions[0]['action']);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }

    public function test_it_does_not_fail_a_long_running_task_that_keeps_progressing(): void
    {
        $task = Task::factory()->create([
            'status' => TaskStatus::Running,
            'started_at' => now()->subHours(3),
            'last_heartbeat_at' => now()->subMinute(),
        ]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame([], $actions);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }

    public function test_recovering_a_task_with_no_checkpoint_returns_status(): void
    {
        $task = Task::factory()->stale()->create();

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame('recovered', $actions[0]['action']);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }

    public function test_recovering_a_stale_task_resets_its_execution_window(): void
    {
        $task = Task::factory()->stale()->create();

        $this->assertTrue($task->last_heartbeat_at->lt(now()->subMinutes(5)));

        $supervisor = $this->app->make(TaskSupervisor::class);

        $supervisor->inspect();

        $this->assertTrue($task->fresh()->last_heartbeat_at->gt(now()->subMinute()));
    }

    public function test_automatic_recovery_consumes_the_bounded_budget(): void
    {
        $task = Task::factory()->stale()->create(['auto_recoveries' => 0]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $supervisor->inspect();

        $this->assertSame(1, $task->fresh()->auto_recoveries);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }

    public function test_it_parks_a_stale_task_once_the_recovery_budget_is_spent(): void
    {
        config(['agent.task.max_auto_recoveries' => 2]);

        $task = Task::factory()->stale()->create(['auto_recoveries' => 2]);

        $supervisor = $this->app->make(TaskSupervisor::class);

        $actions = $supervisor->inspect();

        $this->assertSame('parked_needs_human', $actions[0]['action']);
        $this->assertSame($task->id, $actions[0]['task_id']);

        $task->refresh();
        $this->assertSame(TaskStatus::Waiting, $task->status);
        $this->assertTrue((bool) $task->needs_human_input);
        $this->assertStringContainsString('Awaiting human input', $task->waiting_reason);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
    }
}
