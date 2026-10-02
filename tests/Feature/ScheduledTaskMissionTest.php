<?php

namespace Tests\Feature;

use App\Jobs\AdvanceMissionJob;
use App\Jobs\RunScheduledTaskJob;
use App\Models\AgentReport;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Mission;
use App\Models\ScheduledTask;
use App\Services\Agent\AgentService;
use App\Services\Agent\MissionService;
use App\Services\Tools\ScheduledTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ScheduledTaskMissionTest extends TestCase
{
    use RefreshDatabase;

    private ScheduledTaskTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new ScheduledTaskTool;
    }

    public function test_create_stores_mission_mode_flag(): void
    {
        $output = $this->tool->execute([
            'action' => 'create',
            'name' => 'nightly-build',
            'cron_expression' => '0 2 * * *',
            'prompt' => 'Continue building the site.',
            'run_as_mission' => true,
        ]);

        $this->assertStringContainsString('MISSION mode', $output);
        $task = ScheduledTask::where('name', 'nightly-build')->sole();
        $this->assertTrue($task->run_as_mission);
        $this->assertNotNull($task->next_run_at);
    }

    public function test_update_can_toggle_mission_mode(): void
    {
        $this->tool->execute([
            'action' => 'create',
            'name' => 'toggle-me',
            'cron_expression' => '* * * * *',
            'prompt' => 'Chore.',
        ]);

        $this->assertFalse(ScheduledTask::where('name', 'toggle-me')->sole()->run_as_mission);

        $this->tool->execute(['action' => 'update', 'name' => 'toggle-me', 'run_as_mission' => true]);

        $this->assertTrue(ScheduledTask::where('name', 'toggle-me')->sole()->run_as_mission);
    }

    public function test_list_marks_mission_mode_tasks(): void
    {
        $this->tool->execute([
            'action' => 'create',
            'name' => 'marked',
            'cron_expression' => '* * * * *',
            'prompt' => 'Build.',
            'run_as_mission' => true,
        ]);

        $listing = $this->tool->execute(['action' => 'list']);

        $this->assertStringContainsString('[M]', $listing);
        $this->assertStringContainsString('mission: pending first run', $listing);
    }

    public function test_first_fire_creates_linked_mission_and_scopes_it(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'name' => 'site-builder',
            'description' => 'Build a recipe website.',
            'prompt' => 'Build the recipe website described in the description.',
            'is_active' => true,
            'run_as_mission' => true,
        ]);

        (new RunScheduledTaskJob($task->id))->handle($this->mockNeverCalledAgent(), new MissionService);

        $mission = Mission::where('name', 'site-builder')->sole();
        $this->assertSame('scoping', $mission->status);
        $this->assertSame('Build a recipe website.', $mission->goal);
        $this->assertSame($mission->id, $task->fresh()->mission_id);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'orchestrator'
            && $job->featureId === 0
            && $job->missionId === $mission->id);

        // The heartbeat still respects the schedule and reports.
        $this->assertNotNull($task->fresh()->next_run_at);
        $report = AgentReport::where('meta->scheduled_task_id', $task->id)->sole();
        $this->assertStringContainsString('site-builder', $report->title);
    }

    public function test_fire_auto_starts_scoped_mission_and_queues_work(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'name' => 'auto-start',
            'prompt' => 'Build it.',
            'is_active' => true,
            'run_as_mission' => true,
        ]);
        $mission = Mission::factory()->create(['name' => 'auto-start-mission', 'status' => 'scoping']);
        $mission->features()->create(['title' => 'First feature', 'sort_order' => 1]);
        $task->update(['mission_id' => $mission->id]);

        (new RunScheduledTaskJob($task->id))->handle($this->mockNeverCalledAgent(), new MissionService);

        $mission = $mission->fresh();
        $this->assertSame('active', $mission->status);
        $this->assertNotNull($mission->started_at);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'worker'
            && $job->featureId === $mission->features()->first()->id);
    }

    public function test_fire_rekicks_scoping_when_plan_missing(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'name' => 'stuck-scoping',
            'prompt' => 'Build.',
            'is_active' => true,
            'run_as_mission' => true,
        ]);
        $mission = Mission::factory()->create(['name' => 'stuck-scoping-mission', 'status' => 'scoping']);
        $task->update(['mission_id' => $mission->id]);

        (new RunScheduledTaskJob($task->id))->handle($this->mockNeverCalledAgent(), new MissionService);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'orchestrator'
            && $job->featureId === 0);
        $this->assertSame('scoping', $mission->fresh()->status);
    }

    public function test_fire_does_not_claim_work_while_a_feature_is_in_progress(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'name' => 'busy-heartbeat',
            'prompt' => 'Build.',
            'is_active' => true,
            'run_as_mission' => true,
        ]);
        $mission = Mission::factory()->create(['name' => 'busy-mission', 'status' => 'active']);
        $mission->features()->create(['title' => 'Being worked on', 'sort_order' => 1, 'status' => 'in_progress']);
        $mission->features()->create(['title' => 'Waiting feature', 'sort_order' => 2]);
        $task->update(['mission_id' => $mission->id]);

        (new RunScheduledTaskJob($task->id))->handle($this->mockNeverCalledAgent(), new MissionService);

        Queue::assertNotPushed(AdvanceMissionJob::class);
        $this->assertSame('active', $mission->fresh()->status);
    }

    public function test_fire_respects_paused_missions(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'name' => 'paused-link',
            'prompt' => 'Build.',
            'is_active' => true,
            'run_as_mission' => true,
        ]);
        $mission = Mission::factory()->create(['name' => 'paused-mission', 'status' => 'paused']);
        $task->update(['mission_id' => $mission->id]);

        (new RunScheduledTaskJob($task->id))->handle($this->mockNeverCalledAgent(), new MissionService);

        Queue::assertNotPushed(AdvanceMissionJob::class);
        $this->assertSame('paused', $mission->fresh()->status);
    }

    public function test_standard_task_still_runs_the_agent_normally(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'name' => 'plain-task',
            'prompt' => 'Say hello.',
            'is_active' => true,
            'run_as_mission' => false,
        ]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andReturnUsing(function () use ($task) {
            $conversation = Conversation::create(['title' => "Scheduled: {$task->name}"]);

            return Message::factory()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => 'Hello from the scheduled run.',
            ]);
        });

        (new RunScheduledTaskJob($task->id))->handle($agent, new MissionService);

        $this->assertNotNull(ScheduledTask::find($task->id)->last_run_at);
        $this->assertSame('Hello from the scheduled run.', AgentReport::where('type', 'task_summary')->latest('id')->sole()->content);
        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    /**
     * @return MockInterface&AgentService
     */
    private function mockNeverCalledAgent(): MockInterface
    {
        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldNotReceive('run');

        return $agent;
    }
}
