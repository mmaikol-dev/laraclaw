<?php

namespace Tests\Unit;

use App\Models\AgentMemory;
use App\Models\AgentRoleProfile;
use App\Models\AgentRunOutcome;
use App\Models\Conversation;
use App\Models\Task;
use App\Services\Agent\AgentIdentityService;
use App\Services\Agent\OutcomeRecorder;
use App\Services\Agent\RoleProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recording outcomes only pays off if they are fed back to the agent. These
 * cover the recall half of that loop, including the guarantee that an
 * unverified run is never dressed up as evidence of correctness.
 */
class OutcomeRecallTest extends TestCase
{
    use RefreshDatabase;

    private OutcomeRecorder $recorder;

    private AgentIdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new OutcomeRecorder;
        $this->identity = new AgentIdentityService($this->recorder);
    }

    public function test_a_verified_failure_is_surfaced_so_the_agent_can_avoid_it(): void
    {
        $task = Task::factory()->create(['goal' => 'migrate the billing database']);
        $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, [
            'status' => 'failed',
            'failed_checks' => ['schema is valid'],
            'reason' => 'Failed checks: schema is valid',
        ]);

        $context = $this->identity->buildPromptContext(
            Conversation::factory()->create(),
            'please migrate the billing database again',
        );

        $this->assertStringContainsString('VERIFIED FAILED', $context);
        $this->assertStringContainsString('migrate the billing database', $context);
        $this->assertStringContainsString('do not repeat this approach', $context);
        $this->assertStringContainsString('schema is valid', $context);
    }

    public function test_an_unverified_run_is_labelled_rather_than_presented_as_success(): void
    {
        $conversation = Conversation::factory()->create();
        $this->recorder->recordChatRun($conversation, 'rewrite the onboarding email', AgentRunOutcome::STATUS_COMPLETED, 'Done!');

        $context = $this->identity->buildPromptContext($conversation, 'rewrite the onboarding email');

        $this->assertStringContainsString('NOT VERIFIED', $context);
        $this->assertStringNotContainsString('VERIFIED OK', $context);
        $this->assertStringContainsString('Treat unverified runs as unproven', $context);
    }

    public function test_verified_failures_are_ranked_ahead_of_successes(): void
    {
        $profile = $this->profile('qa_operator');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);

        $task = Task::factory()->for($conversation)->create(['goal' => 'run the regression suite']);
        $this->recorder->recordTask($task, AgentRunOutcome::STATUS_COMPLETED, ['status' => 'passed']);
        $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, [
            'status' => 'failed',
            'failed_checks' => ['regression suite green'],
        ]);

        $outcomes = $this->recorder->relevantOutcomes($profile->id, 'run the regression suite');

        $this->assertSame(AgentRunOutcome::VERDICT_FAILED, $outcomes->first()->verdict);
    }

    public function test_employees_only_see_their_own_experience(): void
    {
        $qa = $this->profile('qa_operator');
        $devops = $this->profile('devops_operator');

        $qaTask = Task::factory()->for(Conversation::factory()->create([
            'role_profile_id' => $qa->id,
        ]))->create(['goal' => 'rotate the api credentials']);
        $this->recorder->recordTask($qaTask, AgentRunOutcome::STATUS_FAILED, [
            'status' => 'failed',
            'failed_checks' => ['credentials rotated'],
        ]);

        $qasView = $this->recorder->relevantOutcomes($qa->id, 'rotate the api credentials');
        $devopsView = $this->recorder->relevantOutcomes($devops->id, 'rotate the api credentials');

        $this->assertCount(1, $qasView);
        $this->assertCount(0, $devopsView, 'A DevOps Operator must not inherit the QA Operator record.');
    }

    public function test_irrelevant_outcomes_are_not_surfaced(): void
    {
        $profile = $this->profile('qa_operator');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);

        $task = Task::factory()->for($conversation)->create(['goal' => 'rotate the api credentials']);
        $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, ['status' => 'failed', 'failed_checks' => ['x']]);

        $context = $this->identity->buildPromptContext($conversation, 'what is the weather today');

        $this->assertStringNotContainsString('rotate the api credentials', $context);
    }

    public function test_task_step_runs_do_not_pollute_the_employee_record(): void
    {
        $profile = $this->profile('qa_operator');
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);

        Task::factory()->for($conversation)->create(['goal' => 'run the suite']);

        $recorded = $this->recorder->recordChatRun(
            $conversation,
            'You are executing one step of the task. Task status: Running. Current step 3 of 6.',
            AgentRunOutcome::STATUS_COMPLETED,
            'done',
        );

        $this->assertNull($recorded, 'Internal step prompts are covered by the task outcome, not the chat record.');
        $this->assertSame(0, $this->recorder->reliabilitySummary($profile->id)['unverified']);
    }

    public function test_track_record_counts_separate_verified_from_unverified(): void
    {
        $profile = $this->profile('qa_operator');

        // A plain conversation the user is talking in.
        $chat = Conversation::factory()->create(['role_profile_id' => $profile->id]);

        // A separate conversation that a task is executing inside.
        $taskConversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
        $task = Task::factory()->for($taskConversation)->create();

        $this->recorder->recordTask($task, AgentRunOutcome::STATUS_COMPLETED, ['status' => 'passed']);
        $this->recorder->recordChatRun($chat, 'chat one', AgentRunOutcome::STATUS_COMPLETED, 'ok');
        $this->recorder->recordChatRun($chat, 'chat two', AgentRunOutcome::STATUS_FAILED, 'boom', [], 'oops');

        $summary = $this->recorder->reliabilitySummary($profile->id);

        $this->assertSame(1, $summary['verified_passed']);
        $this->assertSame(0, $summary['verified_failed']);
        $this->assertSame(2, $summary['unverified']);
    }

    public function test_no_outcome_context_is_added_before_the_employee_has_a_record(): void
    {
        $context = $this->identity->buildPromptContext(
            Conversation::factory()->create(),
            'do something never attempted',
        );

        $this->assertStringNotContainsString('Your own record', $context);
    }

    public function test_each_employee_reads_its_own_memories_but_not_a_rivals(): void
    {
        $qa = $this->profile('qa_operator');
        $devops = $this->profile('devops_operator');

        AgentMemory::query()->create([
            'key' => 'role:qa_operator:staging_smoke',
            'value' => 'Staging smoke tests take 14 minutes; run them off-peak.',
            'category' => 'workflow',
            'scope' => 'role',
            'subject_type' => AgentRoleProfile::class,
            'subject_id' => $qa->id,
        ]);

        $qaContext = $this->identity->buildPromptContext(
            Conversation::factory()->create(['role_profile_id' => $qa->id]),
            'how long do staging smoke tests take',
        );

        $devopsContext = $this->identity->buildPromptContext(
            Conversation::factory()->create(['role_profile_id' => $devops->id]),
            'how long do staging smoke tests take',
        );

        $this->assertStringContainsString('Staging smoke tests take 14 minutes', $qaContext);
        $this->assertStringNotContainsString('Staging smoke tests take 14 minutes', $devopsContext);
    }

    private function profile(string $slug): AgentRoleProfile
    {
        app(RoleProfileService::class)->ensureDefaults();

        return AgentRoleProfile::query()->where('slug', $slug)->firstOrFail();
    }
}
