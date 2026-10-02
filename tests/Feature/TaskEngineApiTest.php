<?php

namespace Tests\Feature;

use App\Jobs\ExecuteTaskStepJob;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEngine\TaskEngine;
use App\Services\TaskEngine\TaskSupervisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TaskEngineApiTest extends TestCase
{
    use RefreshDatabase;

    private Mockery\MockInterface $engine;

    private Mockery\MockInterface $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = Mockery::mock(TaskEngine::class);
        $this->supervisor = Mockery::mock(TaskSupervisor::class);

        $this->app->instance(TaskEngine::class, $this->engine);
        $this->app->instance(TaskSupervisor::class, $this->supervisor);
    }

    public function test_index_lists_tasks(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['goal' => 'First task']);
        Task::factory()->for($user)->create(['goal' => 'Second task']);

        $response = $this->getJson(route('api.engine.tasks.index'));

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        Task::factory()->for($user)->create(['goal' => 'Running task', 'status' => 'running']);
        Task::factory()->for($user)->create(['goal' => 'Done task', 'status' => 'completed']);

        $response = $this->getJson(route('api.engine.tasks.index', ['status' => 'completed']));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.goal', 'Done task');
    }

    public function test_store_validates_the_goal(): void
    {
        $response = $this->postJson(route('api.engine.tasks.store'), ['goal' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('goal');
    }

    public function test_show_returns_task_with_steps(): void
    {
        $task = Task::factory()->create(['goal' => 'Some goal']);
        $task->steps()->create(['sort_order' => 1, 'description' => 'First step']);

        $response = $this->getJson(route('api.engine.tasks.show', $task));

        $response->assertOk();
        $response->assertJsonPath('goal', 'Some goal');
        $response->assertJsonCount(1, 'steps');
    }

    public function test_pause_returns_the_new_status(): void
    {
        $task = Task::factory()->create(['goal' => 'Pause me']);

        $this->engine->shouldReceive('pause')->once()->with(Mockery::on(
            fn (Task $t): bool => $t->is($task)
        ));

        $response = $this->postJson(route('api.engine.tasks.pause', $task));

        $response->assertOk();
    }

    public function test_cancel_returns_the_new_status(): void
    {
        $task = Task::factory()->create(['goal' => 'Cancel me']);

        $this->engine->shouldReceive('cancel')->once()->with(
            Mockery::on(fn (Task $t): bool => $t->is($task)),
        );

        $response = $this->postJson(route('api.engine.tasks.cancel', $task));

        $response->assertOk();
    }

    public function test_execute_enqueues_the_task_and_returns_accepted(): void
    {
        Queue::fake();

        $task = Task::factory()->create(['goal' => 'Run me']);

        $response = $this->postJson(route('api.engine.tasks.execute', $task));

        $response->assertStatus(202);
        $response->assertJsonPath('accepted', true);

        Queue::assertPushed(ExecuteTaskStepJob::class, fn (ExecuteTaskStepJob $job): bool => $job->taskId === $task->id);
    }

    public function test_restart_step_validates_sort_order(): void
    {
        $task = Task::factory()->create(['goal' => 'Restart me']);

        $response = $this->postJson(route('api.engine.tasks.steps.restart', ['task' => $task, 'step' => 1]), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sort_order');
    }

    public function test_supervise_runs_the_watchdog(): void
    {
        $this->supervisor->shouldReceive('inspect')->once()->andReturn([
            ['task_id' => 1, 'action' => 'recovered'],
        ]);

        $response = $this->postJson(route('api.engine.supervise'));

        $response->assertOk();
        $response->assertJsonCount(1, 'actions');
    }
}
