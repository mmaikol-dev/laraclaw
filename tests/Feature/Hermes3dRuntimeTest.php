<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Models\AgentMessage;
use App\Models\AgentRoleProfile;
use App\Models\Conversation;
use App\Models\Mission;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class Hermes3dRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_health_reports_ok(): void
    {
        $this->getJson('/api/hermes3d/health')
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'ok']);
    }

    public function test_state_exposes_runtime_and_active_agents(): void
    {
        AgentRoleProfile::query()->create([
            'slug' => 'general_operator',
            'name' => 'General Operator',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/hermes3d/state')->assertOk();

        $response->assertJsonPath('runtime.name', 'LaraClaw')
            ->assertJsonPath('identity.model_id', config('ollama.agent_model'));

        $this->assertArrayHasKey('general_operator', $response->json('active'));
    }

    public function test_registry_lists_the_active_model(): void
    {
        $response = $this->getJson('/api/hermes3d/registry')->assertOk();

        $this->assertArrayHasKey(config('ollama.agent_model'), $response->json('models'));
    }

    public function test_chat_requires_messages(): void
    {
        $this->postJson('/api/hermes3d/v1/chat/completions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('messages');
    }

    public function test_chat_runs_the_agent_in_a_reused_session_conversation(): void
    {
        $conversation = Conversation::factory()->create();
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Pong.',
        ]);

        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('run')->twice()->andReturn($message);
        $this->app->instance(AgentService::class, $agent);

        $payload = [
            'conversation_id' => 'agent:general_operator:main',
            'messages' => [
                ['role' => 'user', 'content' => 'Ping.'],
            ],
        ];

        $this->postJson('/api/hermes3d/v1/chat/completions', $payload)
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', 'Pong.');

        $this->postJson('/api/hermes3d/v1/chat/completions', $payload)->assertOk();

        $this->assertSame(
            1,
            Conversation::query()->where('external_key', 'agent:general_operator:main')->count(),
        );
    }

    public function test_chat_maps_the_role_to_a_role_profile(): void
    {
        $profile = AgentRoleProfile::query()->create([
            'slug' => 'qa_operator',
            'name' => 'QA Operator',
            'is_active' => true,
        ]);

        $conversation = Conversation::factory()->create();
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Ok.']);

        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andReturn($message);
        $this->app->instance(AgentService::class, $agent);

        $this->postJson('/api/hermes3d/v1/chat/completions', [
            'conversation_id' => 'agent:qa_operator:main',
            'role' => 'qa_operator',
            'messages' => [['role' => 'user', 'content' => 'Run the tests.']],
        ])->assertOk();

        $this->assertSame(
            $profile->id,
            Conversation::query()->where('external_key', 'agent:qa_operator:main')->value('role_profile_id'),
        );
    }

    public function test_presence_lists_active_profiles_as_idle_by_default(): void
    {
        AgentRoleProfile::query()->create([
            'slug' => 'general_operator',
            'name' => 'General Operator',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/hermes3d/presence')->assertOk();

        $response->assertJsonPath('workspaceId', 'laraclaw');

        $agents = collect($response->json('agents'))->keyBy('agentId');
        $this->assertSame('idle', $agents['general_operator']['state']);
        $this->assertSame('idle', $agents['scheduler']['state']);
    }

    public function test_layout_returns_a_desk_per_active_profile(): void
    {
        $this->activeProfile('devops_operator', 'DevOps Operator');

        $response = $this->getJson('/api/hermes3d/layout')->assertOk();

        $this->assertSame(1800, $response->json('width'));

        $desks = $this->layoutDeskIds();

        $this->assertContains('desk-devops_operator', $desks);
        $this->assertContains('desk-scheduler', $desks);
    }

    public function test_layout_furniture_entries_are_unique_and_well_formed(): void
    {
        $this->activeProfile('devops_operator', 'DevOps Operator');

        $furniture = collect($this->getJson('/api/hermes3d/layout')->assertOk()->json('furniture'));

        $this->assertNotEmpty($furniture);
        $this->assertSame($furniture->count(), $furniture->pluck('_uid')->unique()->count());

        $furniture->each(function (array $item): void {
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('x', $item);
            $this->assertArrayHasKey('y', $item);
        });
    }

    public function test_layout_builds_a_server_room_for_the_server_operator(): void
    {
        $this->activeProfile('server_operator', 'Server Operator');

        $furniture = collect($this->getJson('/api/hermes3d/layout')->assertOk()->json('furniture'));

        $this->assertContains('desk-server_operator', $this->layoutDeskIds());
        $this->assertSame(3, $furniture->where('type', 'server_rack')->count());
        $this->assertSame(1, $furniture->where('type', 'server_terminal')->count());

        $agents = $this->presenceAgents();
        $this->assertSame('desk-server_operator', $agents['server_operator']['preferredDeskId']);
    }

    public function test_layout_has_no_server_room_when_the_server_operator_is_absent(): void
    {
        $this->activeProfile('devops_operator', 'DevOps Operator');

        $furniture = collect($this->getJson('/api/hermes3d/layout')->assertOk()->json('furniture'));

        $this->assertSame(0, $furniture->where('type', 'server_rack')->count());
        $this->assertNotContains('desk-server_operator', $this->layoutDeskIds());
    }

    public function test_presence_preferred_desk_id_matches_a_layout_desk(): void
    {
        $this->activeProfile('devops_operator', 'DevOps Operator');

        $desks = $this->layoutDeskIds();
        $agents = $this->presenceAgents();

        $this->assertContains($agents['devops_operator']['preferredDeskId'], $desks);
        $this->assertContains($agents['scheduler']['preferredDeskId'], $desks);
    }

    public function test_presence_marks_a_role_working_for_a_running_task(): void
    {
        $profile = $this->activeProfile('devops_operator', 'DevOps Operator');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
        Task::factory()->running()->create(['conversation_id' => $conversation->id]);

        $agents = $this->presenceAgents();

        $this->assertSame('working', $agents['devops_operator']['state']);
    }

    public function test_presence_marks_a_role_meeting_when_a_task_is_created(): void
    {
        $profile = $this->activeProfile('qa_operator', 'QA Operator');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
        Task::factory()->create([
            'conversation_id' => $conversation->id,
            'status' => TaskStatus::Pending,
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('meeting', $agents['qa_operator']['state']);
    }

    public function test_presence_marks_a_role_error_for_a_recent_failure(): void
    {
        $profile = $this->activeProfile('security_auditor', 'Security Auditor');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
        Task::factory()->failed()->create(['conversation_id' => $conversation->id]);

        $agents = $this->presenceAgents();

        $this->assertSame('error', $agents['security_auditor']['state']);
    }

    public function test_presence_marks_the_scheduler_meeting_for_an_upcoming_task(): void
    {
        ScheduledTask::factory()->create([
            'is_active' => true,
            'next_run_at' => now()->addMinute(),
            'last_run_at' => now()->subHours(2),
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('meeting', $agents['scheduler']['state']);
    }

    public function test_presence_marks_the_scheduler_working_when_a_scheduled_job_is_running(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\RunScheduledTaskJob']),
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('working', $agents['scheduler']['state']);
    }

    public function test_presence_marks_a_role_meeting_when_a_task_is_planning(): void
    {
        $profile = $this->activeProfile('laravel_architect', 'Laravel Architect');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
        Task::factory()->create([
            'conversation_id' => $conversation->id,
            'status' => TaskStatus::Planning,
        ]);

        $this->assertSame('meeting', $this->presenceAgents()['laravel_architect']['state']);
    }

    public function test_presence_follows_the_in_flight_step_role_for_a_multi_agent_task(): void
    {
        $devops = $this->activeProfile('devops_operator', 'DevOps Operator');
        $qa = $this->activeProfile('qa_operator', 'QA Operator');
        $conversation = Conversation::factory()->create(['role_profile_id' => $devops->id]);
        $task = Task::factory()->running()->create(['conversation_id' => $conversation->id]);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => TaskStepStatus::Running,
            'description' => 'Deploy the app',
            'role_profile_id' => $devops->id,
        ]);
        $task->steps()->create([
            'sort_order' => 2,
            'status' => TaskStepStatus::Pending,
            'description' => 'Run regression tests',
            'role_profile_id' => $qa->id,
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('working', $agents['devops_operator']['state']);
        $this->assertSame('idle', $agents['qa_operator']['state']);
    }

    public function test_presence_lists_the_task_engine_specialists(): void
    {
        $agents = $this->presenceAgents();

        $this->assertSame('Planner', $agents['specialist-planner']['name']);
        $this->assertSame('Reviewer', $agents['specialist-reviewer']['name']);
        $this->assertSame('idle', $agents['specialist-planner']['state']);
        $this->assertSame('desk-specialist-planner', $agents['specialist-planner']['preferredDeskId']);
    }

    public function test_layout_returns_a_desk_per_task_engine_specialist(): void
    {
        $desks = $this->layoutDeskIds();

        $this->assertContains('desk-specialist-planner', $desks);
        $this->assertContains('desk-specialist-reviewer', $desks);
        $this->assertContains('desk-specialist-tester', $desks);
    }

    public function test_presence_marks_a_specialist_working_for_an_in_flight_step(): void
    {
        $task = Task::factory()->running()->create(['goal' => 'Improve the checkout flow']);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => TaskStepStatus::Running,
            'description' => 'Review the pull request for regressions',
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('working', $agents['specialist-reviewer']['state']);
        $this->assertSame('idle', $agents['specialist-coder']['state']);
    }

    public function test_presence_marks_a_specialist_error_for_a_recent_failure(): void
    {
        $task = Task::factory()->failed()->create(['goal' => 'Improve the checkout flow']);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => TaskStepStatus::Pending,
            'description' => 'Review the pull request for regressions',
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('error', $agents['specialist-reviewer']['state']);
    }

    public function test_presence_clears_a_specialist_error_once_a_failed_task_is_stale(): void
    {
        $task = Task::factory()->failed()->create([
            'goal' => 'Improve the checkout flow',
            'completed_at' => now()->subMinutes(10),
        ]);

        $task->steps()->create([
            'sort_order' => 1,
            'status' => TaskStepStatus::Pending,
            'description' => 'Review the pull request for regressions',
        ]);

        $agents = $this->presenceAgents();

        $this->assertSame('idle', $agents['specialist-reviewer']['state']);
    }

    public function test_presence_marks_the_planner_working_while_a_task_is_planning(): void
    {
        Task::factory()->create(['status' => TaskStatus::Planning]);

        $agents = $this->presenceAgents();

        $this->assertSame('working', $agents['specialist-planner']['state']);
    }

    public function test_multiple_roles_can_huddle_in_the_meeting_room_at_once(): void
    {
        $qa = $this->activeProfile('qa_operator', 'QA Operator');
        $devops = $this->activeProfile('devops_operator', 'DevOps Operator');

        foreach ([$qa, $devops] as $profile) {
            $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
            Task::factory()->create([
                'conversation_id' => $conversation->id,
                'status' => TaskStatus::Planning,
            ]);
        }

        $agents = $this->presenceAgents();

        $this->assertSame('meeting', $agents['qa_operator']['state']);
        $this->assertSame('meeting', $agents['devops_operator']['state']);
    }

    public function test_presence_gathers_the_whole_team_for_a_scoping_mission(): void
    {
        $this->activeProfile('qa_operator', 'QA Operator');
        $this->activeProfile('devops_operator', 'DevOps Operator');

        Mission::factory()->create(['status' => 'scoping']);

        $agents = $this->presenceAgents();

        $this->assertSame('meeting', $agents['qa_operator']['state']);
        $this->assertSame('meeting', $agents['devops_operator']['state']);
    }

    public function test_presence_sends_the_assigned_role_to_work_for_an_active_mission(): void
    {
        $this->activeProfile('devops_operator', 'DevOps Operator');

        $mission = Mission::factory()->create(['status' => 'active']);
        $feature = $mission->features()->create([
            'title' => 'Deploy the new service to the server',
            'description' => 'Queue and pipeline setup.',
            'status' => 'in_progress',
            'sort_order' => 1,
        ]);
        $mission->update(['current_feature_id' => $feature->id]);

        $this->assertSame('working', $this->presenceAgents()['devops_operator']['state']);
    }

    public function test_orchestrator_huddle_posts_a_kickoff_to_every_active_agent(): void
    {
        $this->activeProfile('qa_operator', 'QA Operator');
        $this->activeProfile('devops_operator', 'DevOps Operator');

        $mission = Mission::factory()->create(['status' => 'scoping']);

        $participants = app(AgentOrchestrator::class)->startHuddle($mission);

        $this->assertSame(AgentRoleProfile::query()->where('is_active', true)->count(), $participants);
        $this->assertSame($participants, AgentMessage::query()->where('kind', 'kickoff')->count());
        $this->assertSame(1, AgentMessage::query()->where('thread_key', 'mission:'.$mission->id)->distinct('thread_key')->count());
    }

    public function test_assignment_message_sends_the_recipient_to_its_desk(): void
    {
        $this->activeProfile('security_auditor', 'Security Auditor');

        AgentMessage::factory()->kind('assignment')->create(['to_agent' => 'security_auditor']);

        $this->assertSame('working', $this->presenceAgents()['security_auditor']['state']);
    }

    public function test_discussion_message_draws_the_recipient_to_the_meeting_room(): void
    {
        $this->activeProfile('laravel_architect', 'Laravel Architect');

        AgentMessage::factory()->kind('discussion')->create(['to_agent' => 'laravel_architect']);

        $this->assertSame('meeting', $this->presenceAgents()['laravel_architect']['state']);
    }

    public function test_stale_messages_no_longer_affect_presence(): void
    {
        $this->activeProfile('general_operator', 'General Operator');

        AgentMessage::factory()->kind('assignment')->create([
            'to_agent' => 'general_operator',
            'created_at' => now()->subMinutes(30),
        ]);

        $this->assertSame('idle', $this->presenceAgents()['general_operator']['state']);
    }

    public function test_agent_messages_endpoint_lists_the_inbox(): void
    {
        $this->activeProfile('qa_operator', 'QA Operator');

        AgentMessage::factory()->create([
            'to_agent' => 'qa_operator',
            'from_agent' => 'orchestrator',
            'body' => 'Please verify the checkout flow.',
        ]);

        $response = $this->getJson('/api/hermes3d/agents/qa_operator/messages')->assertOk();

        $response->assertJsonPath('agentId', 'qa_operator')
            ->assertJsonPath('messages.0.from', 'orchestrator')
            ->assertJsonPath('messages.0.body', 'Please verify the checkout flow.');
    }

    public function test_agent_messages_endpoint_returns_404_for_unknown_agent(): void
    {
        $this->getJson('/api/hermes3d/agents/ghost/messages')->assertStatus(404);
    }

    public function test_agent_messages_endpoint_accepts_a_directed_message(): void
    {
        $this->activeProfile('qa_operator', 'QA Operator');

        $this->postJson('/api/hermes3d/agents/qa_operator/messages', [
            'from' => 'devops_operator',
            'kind' => 'discussion',
            'body' => 'Can you re-run the smoke suite?',
        ])->assertStatus(201);

        $this->assertDatabaseHas('agent_messages', [
            'from_agent' => 'devops_operator',
            'to_agent' => 'qa_operator',
            'kind' => 'discussion',
            'body' => 'Can you re-run the smoke suite?',
        ]);
    }

    public function test_agent_messages_endpoint_requires_a_body(): void
    {
        $this->activeProfile('qa_operator', 'QA Operator');

        $this->postJson('/api/hermes3d/agents/qa_operator/messages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    private function activeProfile(string $slug, string $name): AgentRoleProfile
    {
        return AgentRoleProfile::query()->create([
            'slug' => $slug,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * @return Collection<string, array{agentId: string, name: string, state: string, preferredDeskId: string}>
     */
    private function presenceAgents(): Collection
    {
        return collect($this->getJson('/api/hermes3d/presence')->assertOk()->json('agents'))
            ->keyBy('agentId');
    }

    /**
     * @return array<int, string>
     */
    private function layoutDeskIds(): array
    {
        return collect($this->getJson('/api/hermes3d/layout')->assertOk()->json('furniture'))
            ->where('type', 'desk_cubicle')
            ->pluck('id')
            ->all();
    }
}
