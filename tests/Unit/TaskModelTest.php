<?php

namespace Tests\Unit;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_with_default_state(): void
    {
        $task = Task::query()->create(['goal' => 'Fix the bug']);

        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertSame(0, $task->current_step);
        $this->assertSame(0, $task->total_steps);
        $this->assertSame(5, $task->max_attempts);
    }

    public function test_valid_state_transitions_are_allowed(): void
    {
        $task = Task::factory()->create();

        $this->assertTrue($task->status->canTransitionTo(TaskStatus::Planning));
        $task->transitionTo(TaskStatus::Planning);
        $this->assertSame(TaskStatus::Planning, $task->status);

        $task->transitionTo(TaskStatus::Running);
        $this->assertSame(TaskStatus::Running, $task->status);

        $task->transitionTo(TaskStatus::Verifying);
        $this->assertSame(TaskStatus::Verifying, $task->status);

        $task->transitionTo(TaskStatus::Completed);
        $this->assertSame(TaskStatus::Completed, $task->status);
    }

    public function test_invalid_state_transition_throws(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Pending]);

        $this->expectException(InvalidArgumentException::class);
        $task->transitionTo(TaskStatus::Completed);
    }

    public function test_cannot_go_back_from_completed(): void
    {
        $task = Task::factory()->completed()->create();

        $this->expectException(InvalidArgumentException::class);
        $task->transitionTo(TaskStatus::Running);
    }

    public function test_heartbeat_tracking_and_staleness(): void
    {
        $task = Task::factory()->create([
            'heartbeat_interval_seconds' => 90,
            'last_heartbeat_at' => now(),
        ]);

        $this->assertFalse($task->isStale());
        $this->assertNotNull($task->last_heartbeat_at);

        $task->forceFill(['last_heartbeat_at' => now()->subMinutes(5)])->save();
        $this->assertTrue($task->fresh()->isStale());
    }

    public function test_touch_heartbeat_updates_the_timestamp(): void
    {
        $task = Task::factory()->create([
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $task->touchHeartbeat();

        $this->assertTrue($task->fresh()->last_heartbeat_at->gt(now()->subMinute()));
    }

    public function test_advance_step_increments_current_and_heartbeat(): void
    {
        $task = Task::factory()->create(['current_step' => 2]);

        $task->advanceStep();

        $this->assertSame(3, $task->fresh()->current_step);
        $this->assertNotNull($task->fresh()->last_heartbeat_at);
    }

    public function test_cannot_declare_completion_directly_through_transition(): void
    {
        // The model cannot transition a Running task to Completed directly —
        // it must go through Verifying first.
        $task = Task::factory()->running()->create();

        $this->expectException(InvalidArgumentException::class);
        $task->transitionTo(TaskStatus::Completed);
    }
}
