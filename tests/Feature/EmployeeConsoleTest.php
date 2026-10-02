<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Trigger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Employee console had no coverage at all. Its routes cover the whole
 * autonomous-workforce surface, so a regression here silently disables
 * scheduled tasks, projects, triggers and memories without any test failing.
 */
class EmployeeConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    public function test_it_requires_authentication(): void
    {
        $this->get(route('employee.index'))->assertRedirect(route('login'));
    }

    public function test_index_renders_the_console(): void
    {
        $this->asUser();

        $this->get(route('employee.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('employee/index'));
    }

    public function test_overview_returns_the_whole_workforce_surface(): void
    {
        $this->asUser();

        ScheduledTask::factory()->create(['name' => 'nightly-audit']);
        Project::query()->create(['name' => 'handover', 'goal' => 'Write the handover doc.']);

        $response = $this->getJson(route('employee.overview'))->assertOk();

        foreach ([
            'environment',
            'scheduled_tasks',
            'projects',
            'triggers',
            'memories',
            'reports',
            'role_profiles',
            'findings',
        ] as $key) {
            $this->assertArrayHasKey($key, $response->json(), "overview is missing [{$key}]");
        }

        $this->assertSame('nightly-audit', $response->json('scheduled_tasks.0.name'));
        $this->assertSame('handover', $response->json('projects.0.name'));
    }

    public function test_a_scheduled_task_can_be_toggled_and_deleted(): void
    {
        $this->asUser();

        $task = ScheduledTask::factory()->create(['is_active' => true]);

        $this->patch(route('employee.tasks.toggle', $task))->assertOk();
        $this->assertFalse($task->fresh()->is_active);

        $this->delete(route('employee.tasks.delete', $task))->assertOk();
        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $task->id]);
    }

    public function test_a_trigger_can_be_toggled_and_deleted(): void
    {
        $this->asUser();

        $trigger = Trigger::query()->create([
            'name' => 'incoming-report',
            'type' => 'webhook',
            'config' => ['path' => '/hooks/report'],
            'prompt' => 'File a new report.',
            'is_active' => true,
        ]);

        $this->patch(route('employee.triggers.toggle', $trigger))->assertOk();
        $this->assertFalse($trigger->fresh()->is_active);

        $this->delete(route('employee.triggers.delete', $trigger))->assertOk();
        $this->assertDatabaseMissing('triggers', ['id' => $trigger->id]);
    }

    public function test_continuing_a_project_answers_ok(): void
    {
        $this->asUser();

        $project = Project::query()->create(['name' => 'handover', 'goal' => 'Write the handover doc.']);

        $this->postJson(route('employee.projects.continue', $project))->assertOk();
    }
}
