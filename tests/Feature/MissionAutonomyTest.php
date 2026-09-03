<?php

namespace Tests\Feature;

use App\Jobs\AdvanceMissionJob;
use App\Models\AgentSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Mission;
use App\Models\MissionFeature;
use App\Services\Agent\AgentService;
use App\Services\Agent\MissionService;
use App\Services\Tools\MissionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class MissionAutonomyTest extends TestCase
{
    use RefreshDatabase;

    private MissionTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new MissionTool(new MissionService);
    }

    public function test_create_queues_orchestrator_scoping_unit(): void
    {
        Queue::fake();

        $this->tool->execute(['action' => 'create', 'name' => 'auto-scope', 'goal' => 'Build the thing.']);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'orchestrator'
            && $job->featureId === 0
            && $job->missionId === Mission::where('name', 'auto-scope')->sole()->id);
    }

    public function test_create_skips_scoping_dispatch_when_disabled(): void
    {
        Queue::fake();
        AgentSetting::query()->create([
            'key' => 'mission_auto_scope',
            'value' => 'false',
            'type' => 'bool',
            'label' => 'Mission auto-scoping',
            'description' => 'Queue an orchestrator planning run automatically.',
        ]);

        $this->tool->execute(['action' => 'create', 'name' => 'manual-scope', 'goal' => 'Build it.']);

        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_scoping_unit_runs_for_unscoped_mission_and_tracks_budget(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'scoping-run', 'status' => 'scoping', 'validation_contract' => null]);

        $agent = $this->mockAgentReturningTokens(120, 30);

        (new AdvanceMissionJob($mission->id, 'orchestrator'))->handle($agent, new MissionService);

        $conversation = Conversation::where('title', 'Mission: scoping-run')->sole();
        $this->assertSame($mission->fresh()->conversation_id, $conversation->id);
        $this->assertSame(150, $mission->fresh()->spent_tokens);

        // Scoping must not auto-start the mission; human approval is required.
        $this->assertSame('scoping', $mission->fresh()->status);
        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_scoping_unit_skips_already_scoped_mission(): void
    {
        $mission = Mission::factory()->create(['name' => 'already-scoped', 'status' => 'scoping']);
        $mission->features()->create(['title' => 'Planned feature', 'sort_order' => 1]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldNotReceive('run');

        (new AdvanceMissionJob($mission->id, 'orchestrator'))->handle($agent, new MissionService);

        $this->assertSame(0, $mission->fresh()->spent_tokens);
        $this->assertNull($mission->fresh()->conversation_id);
    }

    public function test_validator_block_triggers_self_healing_rescope(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'heal-me', 'status' => 'active']);
        $feature = $mission->features()->create(['title' => 'Broken feature', 'sort_order' => 1, 'status' => 'blocked']);

        (new AdvanceMissionJob($mission->id, 'validator', $feature->id))->chainNext(new MissionService);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'orchestrator'
            && $job->featureId === $feature->id
            && $job->missionId === $mission->id);
    }

    public function test_rescope_unit_prompts_with_validator_findings(): void
    {
        $mission = Mission::factory()->create([
            'name' => 'rescope-prompt',
            'validation_contract' => ['Suite passes.'],
        ]);
        $feature = $mission->features()->create([
            'title' => 'Flaky checkout',
            'sort_order' => 2,
            'status' => 'blocked',
            'attempts' => 2,
            'notes' => 'Payment step crashes.',
        ]);
        $mission->handoffs()->create([
            'mission_feature_id' => $feature->id,
            'role' => 'validator',
            'summary' => 'Checkout returns 500 on empty cart.',
        ]);

        $prompt = app(MissionService::class)->orchestratorRescopePrompt($mission, $feature);

        $this->assertStringContainsString('Flaky checkout', $prompt);
        $this->assertStringContainsString('Checkout returns 500 on empty cart.', $prompt);
        $this->assertStringContainsString('Payment step crashes.', $prompt);
        $this->assertStringContainsString('Suite passes.', $prompt);
        $this->assertStringContainsString('Follow-up:', $prompt);
    }

    public function test_rescope_unit_skips_feature_that_is_not_blocked(): void
    {
        $mission = Mission::factory()->create(['name' => 'stale-unit', 'status' => 'active']);
        $feature = $mission->features()->create(['title' => 'Fine feature', 'sort_order' => 1, 'status' => 'validated']);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldNotReceive('run');

        Queue::fake();

        (new AdvanceMissionJob($mission->id, 'orchestrator', $feature->id))->handle($agent, new MissionService);

        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_chain_queues_next_worker_after_validated_feature(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'chain-worker', 'status' => 'active']);
        $validated = $mission->features()->create(['title' => 'First feature', 'sort_order' => 1, 'status' => 'validated']);
        $pending = $mission->features()->create(['title' => 'Second feature', 'sort_order' => 2, 'status' => 'pending']);

        (new AdvanceMissionJob($mission->id, 'validator', $validated->id))->chainNext(new MissionService);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'worker'
            && $job->featureId === $pending->id);
    }

    public function test_worker_claim_keeps_implemented_status_awaiting_validation(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'no-clobber', 'status' => 'active']);
        $implemented = $mission->features()->create(['title' => 'Awaiting review', 'sort_order' => 1, 'status' => 'implemented']);

        $next = app(MissionService::class)->queueNextWorkUnit($mission);

        $this->assertNotNull($next);
        $this->assertSame('validator', $next['role']);
        $this->assertSame('implemented', $implemented->fresh()->status);
        $this->assertSame(1, $implemented->fresh()->attempts);
    }

    public function test_next_action_auto_blocks_feature_past_attempt_budget(): void
    {
        AgentSetting::query()->create([
            'key' => 'mission_max_feature_attempts',
            'value' => '3',
            'type' => 'int',
            'label' => 'Mission max feature attempts',
            'description' => 'Auto-block a mission feature after this many attempts.',
        ]);

        $mission = Mission::factory()->create(['name' => 'exhausted', 'status' => 'active', 'context_notes' => null]);
        $stuck = $mission->features()->create(['title' => 'Never validates', 'sort_order' => 1, 'status' => 'implemented', 'attempts' => 3]);
        $fallback = $mission->features()->create(['title' => 'Healthy feature', 'sort_order' => 2]);

        $service = app(MissionService::class);

        $next = $service->nextAction($mission);

        $this->assertNotNull($next);
        $this->assertSame('worker', $next['role']);
        $this->assertSame($fallback->id, $next['feature']->id);
        $this->assertSame('blocked', $stuck->fresh()->status);
        $this->assertStringContainsString('Auto-blocked after 3 attempts', (string) $stuck->fresh()->notes);

        $mission->features()->whereKey($fallback->id)->update(['status' => 'validated']);
        $this->assertNull($service->nextAction($mission));
    }

    public function test_queue_completes_mission_when_no_work_remains(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'all-done', 'status' => 'active', 'completed_at' => null]);
        $mission->features()->create(['title' => 'Done deal', 'sort_order' => 1, 'status' => 'validated']);
        $mission->features()->create(['title' => 'Also done', 'sort_order' => 2, 'status' => 'validated']);

        $next = app(MissionService::class)->queueNextWorkUnit($mission);

        $this->assertNull($next);
        $this->assertSame('completed', $mission->fresh()->status);
        $this->assertNotNull($mission->fresh()->completed_at);
        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_queue_does_not_complete_mission_with_blocked_features(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'has-blocked', 'status' => 'active', 'completed_at' => null]);
        $mission->features()->create(['title' => 'Validated one', 'sort_order' => 1, 'status' => 'validated']);
        $mission->features()->create(['title' => 'Stuck one', 'sort_order' => 2, 'status' => 'blocked']);

        $next = app(MissionService::class)->queueNextWorkUnit($mission);

        $this->assertNull($next);
        $this->assertSame('active', $mission->fresh()->status);
    }

    public function test_job_stops_when_mission_paused_between_units(): void
    {
        $mission = Mission::factory()->create(['name' => 'paused-mission', 'status' => 'paused']);
        $mission->features()->create(['title' => 'Pending thing', 'sort_order' => 1]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldNotReceive('run');

        Queue::fake();

        (new AdvanceMissionJob($mission->id, 'worker'))->chainNext(new MissionService);

        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_validator_prompt_requires_user_testing_tier(): void
    {
        $mission = Mission::factory()->create(['name' => 'qa-tier', 'validation_contract' => ['Login works.']]);
        $feature = $mission->features()->create(['title' => 'Login form', 'sort_order' => 1]);

        $prompt = app(MissionService::class)->validatorPrompt($mission, $feature);

        $this->assertStringContainsString('SCRUTINY VALIDATION', $prompt);
        $this->assertStringContainsString('USER-TESTING VALIDATION', $prompt);
        $this->assertStringContainsString('QA engineer', $prompt);
        $this->assertStringContainsString('browser/web tools', $prompt);
        $this->assertStringContainsString('ADVERSARIAL BY DESIGN', $prompt);
    }

    public function test_crashed_worker_releases_feature_back_to_pending(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'crash-release', 'status' => 'active']);
        $feature = $mission->features()->create(['title' => 'Doomed feature', 'sort_order' => 1, 'status' => 'in_progress']);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andThrow(new \RuntimeException('ollama down'));

        (new AdvanceMissionJob($mission->id, 'worker', $feature->id))->handle($agent, new MissionService);

        $this->assertSame('pending', $feature->fresh()->status);
        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_degraded_response_releases_feature_and_stops_chaining(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'degraded-stop', 'status' => 'active']);
        $first = $mission->features()->create(['title' => 'Feature A', 'sort_order' => 1, 'status' => 'in_progress']);
        $mission->features()->create(['title' => 'Feature B', 'sort_order' => 2]);

        $message = Message::factory()->create([
            'role' => 'assistant',
            'content' => 'LaraClaw could not complete that request: Ollama chat stream failed with status 410.',
            'prompt_tokens' => 10,
            'completion_tokens' => 0,
        ]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andReturn($message);

        (new AdvanceMissionJob($mission->id, 'worker', $first->id))->handle($agent, new MissionService);

        $this->assertSame('pending', $first->fresh()->status);
        // The remaining feature must NOT be claimed by a cascading chain.
        $this->assertSame('pending', $mission->features()->where('title', 'Feature B')->sole()->status);
        Queue::assertNotPushed(AdvanceMissionJob::class);
    }

    public function test_degraded_validator_restores_implemented_status(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'validator-crash', 'status' => 'active']);
        $feature = $mission->features()->create(['title' => 'Built feature', 'sort_order' => 1, 'status' => 'in_progress']);

        $message = Message::factory()->create([
            'role' => 'assistant',
            'content' => 'LaraClaw could not complete that request: connection refused.',
        ]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andReturn($message);

        (new AdvanceMissionJob($mission->id, 'validator', $feature->id))->handle($agent, new MissionService);

        $this->assertSame('implemented', $feature->fresh()->status);
    }

    public function test_successful_worker_marks_feature_implemented_for_validation(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'worker-done', 'status' => 'active']);
        $feature = $mission->features()->create(['title' => 'Built feature', 'sort_order' => 1, 'status' => 'in_progress']);

        $agent = $this->mockAgentReturningTokens(100, 20);

        (new AdvanceMissionJob($mission->id, 'worker', $feature->id))->handle($agent, new MissionService);

        // Even though the agent never called update_feature itself.
        $this->assertSame('implemented', $feature->fresh()->status);
    }

    public function test_validator_rejection_word_blocks_feature_for_rescope(): void
    {
        Queue::fake();
        $mission = Mission::factory()->create(['name' => 'validator-reject', 'status' => 'active']);
        $feature = $mission->features()->create(['title' => 'Flawed feature', 'sort_order' => 1, 'status' => 'implemented']);
        $mission->features()->create(['title' => 'Later feature', 'sort_order' => 2]);

        $message = Message::factory()->create([
            'role' => 'assistant',
            'content' => 'The form does not submit; assertions 1 and 3 fail. Feature BLOCKED pending rescope.',
        ]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andReturn($message);

        (new AdvanceMissionJob($mission->id, 'validator', $feature->id))->handle($agent, new MissionService);

        $this->assertSame('blocked', $feature->fresh()->status);
        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'orchestrator'
            && $job->featureId === $feature->id);
    }

    /**
     * @return MockInterface&AgentService
     */
    private function mockAgentReturningTokens(int $promptTokens, int $completionTokens): MockInterface
    {
        $message = Message::factory()->create([
            'role' => 'assistant',
            'content' => 'Plan drafted.',
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ]);

        /** @var MockInterface&AgentService $agent */
        $agent = $this->mock(AgentService::class);
        $agent->shouldReceive('run')->once()->andReturn($message);

        return $agent;
    }
}
