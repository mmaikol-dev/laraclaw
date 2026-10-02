<?php

namespace Tests\Feature;

use App\Events\TaskCompleted;
use App\Events\TaskCreated;
use App\Events\TaskEscalated;
use App\Events\TaskFailed;
use App\Models\Event;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_created_event_is_persisted_to_the_events_table(): void
    {
        $task = Task::factory()->create(['goal' => 'Fix the login bug']);

        event(new TaskCreated($task));

        $this->assertDatabaseHas('events', [
            'event_type' => 'task.created',
            'entity_type' => 'task',
            'entity_id' => (string) $task->id,
            'level' => 'info',
        ]);
    }

    public function test_task_completed_event_is_persisted(): void
    {
        $task = Task::factory()->completed()->create();

        event(new TaskCompleted($task));

        $logged = Event::query()->where('event_type', 'task.completed')->first();

        $this->assertNotNull($logged);
        $this->assertSame('success', $logged->level);
        $this->assertSame('completed', $logged->data['status']);
    }

    public function test_task_failed_event_is_persisted_with_reason(): void
    {
        $task = Task::factory()->failed()->create();

        event(new TaskFailed($task, 'Tests failed'));

        $logged = Event::query()->where('event_type', 'task.failed')->first();

        $this->assertNotNull($logged);
        $this->assertSame('error', $logged->level);
        $this->assertSame('Tests failed', $logged->message);
        $this->assertSame((string) $task->id, $logged->entity_id);
    }

    public function test_task_escalated_event_is_persisted(): void
    {
        $task = Task::factory()->running()->create();

        event(new TaskEscalated($task, 'simple:v1', 'review:v1', 'Step 1 retried 3 times'));

        $logged = Event::query()->where('event_type', 'task.escalated')->first();

        $this->assertNotNull($logged);
        $this->assertSame('warning', $logged->level);
        $this->assertStringContainsString('simple:v1', $logged->message);
        $this->assertStringContainsString('review:v1', $logged->message);
    }

    public function test_dispatched_run_is_not_required_for_logging(): void
    {
        // Even without an active run, listeners log to the DB table.
        $task = Task::factory()->create();

        event(new TaskCreated($task));

        $this->assertDatabaseCount('events', 1);
    }

    public function test_task_events_record_current_step_progress(): void
    {
        $task = Task::factory()->running()->create([
            'current_step' => 3,
            'total_steps' => 5,
        ]);

        event(new TaskCreated($task));

        $logged = Event::query()->where('event_type', 'task.created')->first();

        $this->assertSame($task->status->value, $logged->data['status']);
        $this->assertSame(3, $logged->data['current_step']);
        $this->assertSame(5, $logged->data['total_steps']);
    }
}
