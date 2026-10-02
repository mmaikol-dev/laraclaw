<?php

namespace Tests\Unit;

use App\Enums\TaskFailureType;
use App\Enums\TaskStepStatus;
use App\Models\Task;
use App\Models\TaskStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TaskStepModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_default_state_and_ordering(): void
    {
        $task = Task::factory()->create();

        $task->steps()->create(['sort_order' => 1, 'description' => 'First']);
        $task->steps()->create(['sort_order' => 2, 'description' => 'Second']);

        $steps = $task->steps;
        $this->assertCount(2, $steps);
        $this->assertSame(TaskStepStatus::Pending, $steps[0]->status);
        $this->assertSame(1, $steps[0]->sort_order);
        $this->assertSame(2, $steps[1]->sort_order);
    }

    public function test_step_state_transitions(): void
    {
        $step = $this->makeStep();

        $step->transitionTo(TaskStepStatus::Running);
        $this->assertSame(TaskStepStatus::Running, $step->status);

        $step->transitionTo(TaskStepStatus::Completed);
        $this->assertSame(TaskStepStatus::Completed, $step->status);
    }

    public function test_invalid_step_transition_throws(): void
    {
        $step = $this->makeStep();

        $this->expectException(InvalidArgumentException::class);
        $step->transitionTo(TaskStepStatus::Completed);
    }

    public function test_mark_completed_sets_result_and_timestamp(): void
    {
        $step = $this->makeStep();
        $step->markRunning();

        $step->markCompleted('All done');

        $this->assertSame(TaskStepStatus::Completed, $step->fresh()->status);
        $this->assertSame('All done', $step->fresh()->result);
        $this->assertNotNull($step->fresh()->completed_at);
    }

    public function test_mark_failed_records_error_and_type(): void
    {
        $step = $this->makeStep();
        $step->markRunning();

        $step->markFailed('Something broke', TaskFailureType::NetworkError);

        $this->assertSame(TaskStepStatus::Failed, $step->fresh()->status);
        $this->assertSame('Something broke', $step->fresh()->error);
        $this->assertSame(TaskFailureType::NetworkError, $step->fresh()->failure_type);
    }

    public function test_dependencies_are_respected(): void
    {
        $task = Task::factory()->create();
        $step1 = $task->steps()->create(['sort_order' => 1, 'description' => 'One']);
        $step2 = $task->steps()->create(['sort_order' => 2, 'description' => 'Two', 'depends_on' => [1]]);

        $this->assertFalse($step2->dependenciesMet());

        $step1->markRunning();
        $step1->markCompleted('ok');

        $this->assertTrue($step2->fresh()->dependenciesMet());
    }

    public function test_retry_limits_are_respected(): void
    {
        $step = $this->makeStep(['max_attempts' => 2, 'attempts' => 1]);
        $step->markRunning();

        $this->assertTrue($step->canRetry());

        $step->markRetrying();
        $this->assertSame(2, $step->fresh()->attempts);
        $this->assertFalse($step->fresh()->canRetry());
    }

    private function makeStep(array $attrs = []): TaskStep
    {
        $task = Task::factory()->create();

        return $task->steps()->create(array_merge([
            'sort_order' => 1,
            'description' => 'A step',
        ], $attrs));
    }
}
