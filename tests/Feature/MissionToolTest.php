<?php

namespace Tests\Feature;

use App\Jobs\AdvanceMissionJob;
use App\Models\Mission;
use App\Services\Agent\MissionService;
use App\Services\Tools\MissionTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MissionToolTest extends TestCase
{
    use RefreshDatabase;

    private MissionTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new MissionTool(new MissionService);
        Queue::fake();
    }

    public function test_full_scoping_to_start_lifecycle(): void
    {
        $output = $this->tool->execute([
            'action' => 'create',
            'name' => 'api-rebuild',
            'goal' => 'Rebuild the public API with versioning.',
        ]);

        $this->assertStringContainsString("Mission 'api-rebuild' created in scoping", $output);

        $this->tool->execute([
            'action' => 'set_contract',
            'mission_name' => 'api-rebuild',
            'assertions' => json_encode([
                'GET /api/v1/posts returns paginated JSON.',
                'Unauthenticated writes are rejected with 401.',
                'The full test suite passes.',
            ]),
        ]);

        $featureOutput = $this->tool->execute([
            'action' => 'add_feature',
            'mission_name' => 'api-rebuild',
            'feature_title' => 'Posts endpoint',
            'feature_description' => 'CRUD endpoints for posts.',
            'milestone' => 'M1: Core API',
        ]);

        $this->assertStringContainsString("Feature [1] 'Posts endpoint' added", $featureOutput);

        $startOutput = $this->tool->execute(['action' => 'start', 'mission_name' => 'api-rebuild']);

        $this->assertStringContainsString("Mission 'api-rebuild' started", $startOutput);
        $this->assertDatabaseHas('missions', ['name' => 'api-rebuild', 'status' => 'active']);
    }

    public function test_start_requires_contract_and_features(): void
    {
        $this->tool->execute(['action' => 'create', 'name' => 'empty-mission', 'goal' => 'Nothing.']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot start without a Validation Contract');

        $this->tool->execute(['action' => 'start', 'mission_name' => 'empty-mission']);
    }

    public function test_advance_queues_validator_for_implemented_feature(): void
    {
        Queue::fake();

        $mission = Mission::factory()->create(['name' => 'serial-mission', 'status' => 'active']);
        $implemented = $mission->features()->create([
            'title' => 'Implemented feature',
            'sort_order' => 1,
            'status' => 'implemented',
        ]);
        $pending = $mission->features()->create([
            'title' => 'Pending feature',
            'sort_order' => 2,
            'status' => 'pending',
        ]);

        $output = $this->tool->execute(['action' => 'advance', 'mission_name' => 'serial-mission']);

        $this->assertStringContainsString('Queued validator step', $output);
        $this->assertStringContainsString('Implemented feature', $output);

        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'validator' && $job->featureId === $implemented->id);

        $this->assertSame($implemented->id, $mission->fresh()->current_feature_id);
        $this->assertSame(1, $implemented->fresh()->attempts);

        // Second advance should queue the validator again until validation passes — serial execution.
        $this->tool->execute(['action' => 'advance', 'mission_name' => 'serial-mission']);

        Queue::assertPushed(AdvanceMissionJob::class, 2);

        // Neither feature may start work while validation is still pending.
        $this->assertSame('implemented', $implemented->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_advance_queues_worker_when_nothing_awaits_validation(): void
    {
        Queue::fake();

        $mission = Mission::factory()->create(['name' => 'worker-mission', 'status' => 'active']);
        $pending = $mission->features()->create([
            'title' => 'Next feature',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        $output = $this->tool->execute(['action' => 'advance', 'mission_name' => 'worker-mission']);

        $this->assertStringContainsString('Queued worker step', $output);
        Queue::assertPushed(AdvanceMissionJob::class, fn (AdvanceMissionJob $job): bool => $job->role === 'worker' && $job->featureId === $pending->id);
        $this->assertSame('in_progress', $pending->fresh()->status);
    }

    public function test_record_handoff_stores_structured_report_and_context(): void
    {
        $mission = Mission::factory()->create(['name' => 'handoff-mission', 'context_notes' => null]);
        $feature = $mission->features()->create(['title' => 'Auth module', 'sort_order' => 1, 'status' => 'in_progress']);
        $mission->update(['current_feature_id' => $feature->id]);

        $output = $this->tool->execute([
            'action' => 'record_handoff',
            'mission_name' => 'handoff-mission',
            'role' => 'worker',
            'summary' => 'Implemented login flow.',
            'completed_work' => 'Login + session persistence.',
            'undone_work' => 'Password reset deferred.',
            'execution_log' => json_encode([['command' => 'php artisan test', 'exit_code' => 0]]),
            'discovered_issues' => 'Rate limiter config is missing.',
            'procedure_adhered' => true,
        ]);

        $this->assertStringContainsString("Handoff recorded for mission 'handoff-mission'", $output);

        $handoff = $mission->handoffs()->firstOrFail();
        $this->assertSame('worker', $handoff->role);
        $this->assertSame($feature->id, $handoff->mission_feature_id);
        $this->assertSame('Implemented login flow.', $handoff->summary);
        $this->assertSame(0, $handoff->execution_log[0]['exit_code']);
        $this->assertTrue($handoff->procedure_adhered);

        // Findings must persist into mission context so future workers inherit them.
        $this->assertStringContainsString('Rate limiter config is missing.', (string) $mission->fresh()->context_notes);
        $this->assertStringContainsString('Password reset deferred.', (string) $mission->fresh()->context_notes);
    }

    public function test_set_models_rejects_matching_worker_and_validator(): void
    {
        Mission::factory()->create(['name' => 'bias-mission']);

        try {
            $this->tool->execute([
                'action' => 'set_models',
                'mission_name' => 'bias-mission',
                'worker_model' => 'opencode/big-pickle',
                'validator_model' => 'opencode/big-pickle',
            ]);
            $this->fail('Identical worker/validator models should be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('different model/provider for the validator', $e->getMessage());
        }
    }

    public function test_complete_blocked_until_features_finished(): void
    {
        $mission = Mission::factory()->create(['name' => 'unfinished-mission']);
        $mission->features()->create(['title' => 'Still pending', 'sort_order' => 1]);

        try {
            $this->tool->execute(['action' => 'complete', 'mission_name' => 'unfinished-mission']);
            $this->fail('Completing a mission with unfinished features should fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still has 1 unfinished feature', $e->getMessage());
        }

        $mission->features()->update(['status' => 'validated']);
        $output = $this->tool->execute(['action' => 'complete', 'mission_name' => 'unfinished-mission']);

        $this->assertStringContainsString("Mission 'unfinished-mission' marked completed", $output);
        $this->assertNotNull($mission->fresh()->completed_at);
    }

    public function test_worker_prompt_is_clean_slate_and_includes_assertions(): void
    {
        $mission = Mission::factory()->create([
            'name' => 'prompt-mission',
            'validation_contract' => ['Suite passes.', 'API returns JSON.'],
            'context_notes' => 'Previous issue: rate limiter.',
        ]);
        $feature = $mission->features()->create(['title' => 'Posts table', 'sort_order' => 3, 'description' => 'Create schema']);

        $prompt = app(MissionService::class)->workerPrompt($mission, $feature);

        $this->assertStringContainsString('CLEAN SLATE', $prompt);
        $this->assertStringContainsString('Posts table', $prompt);
        $this->assertStringContainsString('Create schema', $prompt);
        $this->assertStringContainsString('Suite passes.', $prompt);
        $this->assertStringContainsString('rate limiter.', $prompt);
        $this->assertStringNotContainsString('Pending feature', $prompt);
    }

    public function test_validator_prompt_is_adversarial_and_lists_contract(): void
    {
        $mission = Mission::factory()->create([
            'name' => 'adversarial-mission',
            'validator_model' => 'other-provider/model-x',
            'validation_contract' => ['Coverage above 90%.'],
        ]);
        $feature = $mission->features()->create(['title' => 'Posts table', 'sort_order' => 1]);

        $prompt = app(MissionService::class)->validatorPrompt($mission, $feature);

        $this->assertStringContainsString('ADVERSARIAL BY DESIGN', $prompt);
        $this->assertStringContainsString('other-provider/model-x', $prompt);
        $this->assertStringContainsString('Coverage above 90%.', $prompt);
    }
}
