<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app configures Date::use(CarbonImmutable::class), so now() returns a
 * CarbonImmutable — which is not an Illuminate\Support\Carbon. The agent task
 * pages had no coverage at all, so a type hint that rejected the application's
 * own date instances shipped undetected and broke the page as soon as task
 * rows started persisting.
 */
class AgentTasksPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_index_renders_with_tasks_across_every_status(): void
    {
        Task::factory()->running()->create(['user_id' => $this->user->id, 'total_steps' => 4, 'current_step' => 2]);
        Task::factory()->completed()->create(['user_id' => $this->user->id]);
        Task::factory()->failed('step 2 blew up')->create(['user_id' => $this->user->id]);
        Task::factory()->create(['user_id' => $this->user->id, 'status' => TaskStatus::Paused]);

        $response = $this->actingAs($this->user)->get('/agent-tasks');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('agent-tasks/index')
            ->has('tasks')
            ->has('metrics')
            ->has('filters')
        );
    }

    public function test_index_computes_staleness_from_the_heartbeat_threshold(): void
    {
        $fresh = Task::factory()->running()->create([
            'user_id' => $this->user->id,
            'last_heartbeat_at' => now(),
        ]);

        $stale = Task::factory()->running()->create([
            'user_id' => $this->user->id,
            'last_heartbeat_at' => now()->subHours(2),
        ]);

        $serialized = $this->actingAs($this->user)
            ->get('/agent-tasks')
            ->viewData('page')['props']['tasks']['data'];

        $byId = collect($serialized)->keyBy('id');

        $this->assertFalse($byId[$fresh->id]['is_stale'], 'A task with a current heartbeat is not stale.');
        $this->assertTrue($byId[$stale->id]['is_stale'], 'A task whose heartbeat expired is stale.');
        $this->assertSame(1, $this->actingAs($this->user)->get('/agent-tasks')->viewData('page')['props']['metrics']['stale']);
    }

    public function test_show_renders_a_task_with_steps_and_checkpoints(): void
    {
        $task = Task::factory()->running()->create([
            'user_id' => $this->user->id,
            'goal' => 'Audit the dependency tree',
            'acceptance_criteria' => ['The report file exists.'],
            'total_steps' => 2,
            'current_step' => 1,
        ]);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => TaskStepStatus::Completed,
            'description' => 'Collect installed versions',
            'prompt' => 'Collect installed versions',
        ]);

        $task->checkpoints()->create([
            'step_sort_order' => 1,
            'task_status' => 'running',
            'action_taken' => 'Ran composer show',
            'action_result' => 'Collected 14 direct PHP dependencies',
        ]);

        $response = $this->actingAs($this->user)->get("/agent-tasks/{$task->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('agent-tasks/show')
            ->where('task.id', $task->id)
            ->where('task.goal', 'Audit the dependency tree')
            ->has('task.steps', 1)
            ->has('task.checkpoints', 1)
            ->has('context')
            ->has('context_summary')
            ->has('specialists')
        );
    }

    public function test_index_can_filter_by_status(): void
    {
        Task::factory()->running()->create(['user_id' => $this->user->id]);
        Task::factory()->completed()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get('/agent-tasks?status=completed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'completed')
                ->has('tasks.data', 1)
            );
    }

    public function test_index_rejects_an_unknown_status_filter(): void
    {
        $this->actingAs($this->user)
            ->get('/agent-tasks?status=nonsense')
            ->assertOk();
    }
}
