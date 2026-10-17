<?php

namespace Tests\Feature;

use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;
use App\Models\TaskCheckpoint;
use App\Models\TaskStep;
use App\Services\Tools\TaskEngineTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TaskEngineToolTest extends TestCase
{
    use RefreshDatabase;

    private TaskEngineTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new TaskEngineTool($this->app);
    }

    public function test_create_dispatches_execution_and_returns_task_id(): void
    {
        Queue::fake();

        $output = $this->tool->execute([
            'action' => 'create',
            'goal' => 'Research the job market and apply to matching roles',
            'acceptance_criteria' => ['Application submitted'],
        ]);

        Queue::assertPushed(ExecuteTaskStepJob::class);

        $task = Task::query()->where('goal', 'Research the job market and apply to matching roles')->sole();

        $this->assertStringContainsString("#{$task->id}", $output);
        $this->assertStringContainsString("/agent-tasks/{$task->id}", $output);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Pending->value,
            'goal' => 'Research the job market and apply to matching roles',
        ]);

        $this->assertSame(['Application submitted'], $task->acceptance_criteria);
    }

    public function test_create_without_goal_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('goal is required');

        $this->tool->execute(['action' => 'create']);
    }

    public function test_execute_enqueues_an_existing_task(): void
    {
        Queue::fake();

        $task = Task::factory()->create();

        $output = $this->tool->execute(['action' => 'execute', 'task_id' => $task->id]);

        Queue::assertPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString("Task #{$task->id} re-queued", $output);
    }

    public function test_execute_refuses_terminal_task(): void
    {
        Queue::fake();

        $task = Task::factory()->create(['status' => TaskStatus::Completed]);

        $output = $this->tool->execute(['action' => 'execute', 'task_id' => $task->id]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString('final state', $output);
    }

    public function test_status_reports_real_persisted_progress(): void
    {
        $task = Task::factory()->create(['goal' => 'Apply to four jobs', 'current_step' => 2, 'total_steps' => 4]);
        TaskStep::factory()->create(['task_id' => $task->id, 'sort_order' => 1, 'description' => 'Find leads', 'status' => TaskStepStatus::Completed]);
        TaskStep::factory()->create(['task_id' => $task->id, 'sort_order' => 2, 'description' => 'Draft cover letters', 'status' => TaskStepStatus::Pending]);
        TaskCheckpoint::create([
            'task_id' => $task->id,
            'step_sort_order' => 1,
            'action_taken' => 'Found 3 matching leads',
            'action_result' => 'Benzinga, Tessera Labs, AI Whistleblower',
        ]);

        $output = $this->tool->execute(['action' => 'status', 'task_id' => $task->id]);

        $this->assertStringContainsString("Task #{$task->id}: pending", $output);
        $this->assertStringContainsString('step 2 of 4', $output);
        $this->assertStringContainsString('Next unfinished step: Draft cover letters', $output);
        $this->assertStringContainsString('Latest checkpoint: Found 3 matching leads', $output);
    }

    public function test_list_shows_recent_tasks(): void
    {
        Task::factory()->create(['goal' => 'Alpha goal', 'status' => TaskStatus::Running, 'current_step' => 1, 'total_steps' => 3]);
        Task::factory()->create(['goal' => 'Beta goal', 'status' => TaskStatus::Completed, 'current_step' => 2, 'total_steps' => 2]);

        $output = $this->tool->execute(['action' => 'list', 'limit' => 5]);

        $this->assertStringContainsString('Alpha goal', $output);
        $this->assertStringContainsString('Beta goal', $output);
        $this->assertStringContainsString('running', $output);
        $this->assertStringContainsString('completed', $output);
    }

    public function test_retry_requires_explicit_user_confirmation(): void
    {
        Queue::fake();

        $task = Task::factory()->failed()->create();

        $output = $this->tool->execute(['action' => 'retry', 'task_id' => $task->id]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString('user-initiated', $output);
    }

    public function test_retry_restarts_a_failed_task(): void
    {
        Queue::fake();

        $task = Task::factory()->failed()->create();
        $step = TaskStep::factory()->failed()->create([
            'task_id' => $task->id,
            'sort_order' => 1,
            'description' => 'Deploy the site',
        ]);

        $output = $this->tool->execute([
            'action' => 'retry',
            'task_id' => $task->id,
            'confirmed_by_user' => true,
        ]);

        Queue::assertPushed(ExecuteTaskStepJob::class, fn (ExecuteTaskStepJob $job): bool => $job->taskId === $task->id);
        $this->assertStringContainsString('Retrying task', $output);

        $this->assertSame(TaskStepStatus::Pending, $step->fresh()->status);
        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
        $this->assertNull($task->fresh()->failure_reason);
    }

    public function test_retry_recovers_a_stalled_task(): void
    {
        Queue::fake();

        $task = Task::factory()->stale()->create(['current_step' => 2]);

        $output = $this->tool->execute([
            'action' => 'retry',
            'task_id' => $task->id,
            'confirmed_by_user' => true,
        ]);

        Queue::assertPushed(ExecuteTaskStepJob::class, fn (ExecuteTaskStepJob $job): bool => $job->taskId === $task->id);
        $this->assertStringContainsString('Recovered stalled task', $output);

        $task->refresh();
        $this->assertSame(TaskStatus::Running, $task->status);
        $this->assertTrue($task->last_heartbeat_at->greaterThan(now()->subMinute()));
    }

    public function test_retry_leaves_a_healthy_running_task_alone(): void
    {
        Queue::fake();

        $task = Task::factory()->running()->create();

        $output = $this->tool->execute([
            'action' => 'retry',
            'task_id' => $task->id,
            'confirmed_by_user' => true,
        ]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString('not stalled', $output);
    }

    public function test_retry_does_not_touch_a_paused_task(): void
    {
        Queue::fake();

        $task = Task::factory()->create(['status' => TaskStatus::Paused]);

        $output = $this->tool->execute([
            'action' => 'retry',
            'task_id' => $task->id,
            'confirmed_by_user' => true,
        ]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString('paused', $output);
        $this->assertSame(TaskStatus::Paused, $task->fresh()->status);
    }

    public function test_retry_refuses_a_cancelled_task(): void
    {
        Queue::fake();

        $task = Task::factory()->create(['status' => TaskStatus::Cancelled]);

        $output = $this->tool->execute([
            'action' => 'retry',
            'task_id' => $task->id,
            'confirmed_by_user' => true,
        ]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString('cancelled', $output);
    }

    public function test_retry_reports_a_completed_task(): void
    {
        Queue::fake();

        $task = Task::factory()->completed()->create();

        $output = $this->tool->execute([
            'action' => 'retry',
            'task_id' => $task->id,
            'confirmed_by_user' => true,
        ]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);
        $this->assertStringContainsString('already completed', $output);
    }

    public function test_retry_without_task_id_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task_id is required');

        $this->tool->execute(['action' => 'retry', 'confirmed_by_user' => true]);
    }

    public function test_unknown_action_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported task_engine action');

        $this->tool->execute(['action' => 'nope']);
    }

    public function test_stale_lists_stalled_and_human_input_tasks(): void
    {
        Queue::fake();

        $stale = Task::factory()->stale()->create(['goal' => 'Stalled deploy']);
        $parked = Task::factory()->create([
            'goal' => 'Needs a human',
            'status' => TaskStatus::Waiting,
            'needs_human_input' => true,
            'failure_type' => TaskFailureType::PermissionError,
        ]);

        $output = $this->tool->execute(['action' => 'stale']);

        $this->assertStringContainsString('Stalled deploy', $output);
        $this->assertStringContainsString("#{$stale->id}", $output);
        $this->assertStringContainsString('Awaiting human input', $output);
        $this->assertStringContainsString("#{$parked->id}", $output);
    }

    public function test_stale_reports_when_nothing_is_stalled(): void
    {
        Task::factory()->running()->create();

        $output = $this->tool->execute(['action' => 'stale']);

        $this->assertStringContainsString('No stale tasks', $output);
    }
}
