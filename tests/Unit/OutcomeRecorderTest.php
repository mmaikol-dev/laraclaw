<?php

namespace Tests\Unit;

use App\Models\AgentRoleProfile;
use App\Models\AgentRunOutcome;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\TaskStep;
use App\Services\Agent\OutcomeRecorder;
use App\Services\Agent\RoleProfileService;
use App\Services\TaskEngine\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The self-improvement loop is only as trustworthy as what it records. Before
 * this recorder existed the sole outcome trace was the assistant's own last
 * message, which is narration rather than evidence.
 */
class OutcomeRecorderTest extends TestCase
{
    use RefreshDatabase;

    private OutcomeRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new OutcomeRecorder;
    }

    public function test_chat_runs_are_never_recorded_as_verified(): void
    {
        $conversation = Conversation::factory()->create();

        $outcome = $this->recorder->recordChatRun(
            $conversation,
            'summarise the billing module',
            AgentRunOutcome::STATUS_COMPLETED,
            'Here is the summary you asked for.',
        );

        $this->assertSame(AgentRunOutcome::VERDICT_UNVERIFIED, $outcome->verdict);
        $this->assertSame('none', $outcome->verdict_source);
        $this->assertFalse($outcome->wasVerified());
    }

    public function test_a_verified_task_records_the_verifiers_verdict_not_the_claim(): void
    {
        $task = Task::factory()->create(['goal' => 'add a health endpoint', 'model' => 'gemma4:cloud']);

        $outcome = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_COMPLETED, [
            'status' => 'passed',
            'passed' => ['The file `/tmp/ok.md` exists.'],
            'failed_checks' => [],
        ]);

        $this->assertSame(AgentRunOutcome::VERDICT_PASSED, $outcome->verdict);
        $this->assertSame('verifier', $outcome->verdict_source);
        $this->assertTrue($outcome->wasVerified());
        $this->assertSame('add a health endpoint', $outcome->intent);
        $this->assertSame('gemma4:cloud', $outcome->model);
        $this->assertNull($outcome->failure_type);
    }

    public function test_failed_verification_records_which_criteria_were_unmet(): void
    {
        $task = Task::factory()->create(['goal' => 'write the report']);

        $outcome = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, [
            'status' => 'failed',
            'failed_checks' => ['The file `/tmp/report.md` exists.', 'A summary is present.'],
            'reason' => 'Failed checks: The file `/tmp/report.md` exists., A summary is present.',
        ]);

        $this->assertSame(AgentRunOutcome::VERDICT_FAILED, $outcome->verdict);
        $this->assertSame('criteria_unmet', $outcome->failure_type);
        $this->assertStringContainsString('The file `/tmp/report.md` exists.', (string) $outcome->failure_reason);
        $this->assertStringContainsString('A summary is present.', (string) $outcome->failure_reason);
    }

    public function test_it_understands_the_real_verifier_result_shape(): void
    {
        $task = Task::factory()->create(['goal' => 'produce the artefact']);
        $result = (new VerificationService)->verify(
            Task::factory()->create(['acceptance_criteria' => ['The file `/definitely/missing.md` exists.']])
        );

        $outcome = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, $result);

        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['failed_checks'], 'Guard: the verifier must actually have failed a check.');
        $this->assertSame(AgentRunOutcome::VERDICT_FAILED, $outcome->verdict);
        $this->assertSame('criteria_unmet', $outcome->failure_type);
        $this->assertStringContainsString('Unmet checks: Required files', (string) $outcome->failure_reason);
    }

    public function test_a_failure_record_stays_actionable_when_read_back_later(): void
    {
        $task = Task::factory()->create([
            'goal' => 'produce the artefact',
            'acceptance_criteria' => ['The file `/tmp/report.md` exists.'],
        ]);

        $result = (new VerificationService)->verify($task);
        $outcome = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, $result);

        $this->assertStringContainsString(
            'The file `/tmp/report.md` exists.',
            (string) $outcome->failure_reason,
            'The record must carry the criterion that was actually required, not just the check name.',
        );
    }

    public function test_a_failed_task_with_no_verifier_result_stays_unverified(): void
    {
        $task = Task::factory()->create();

        $outcome = $this->recorder->recordTask(
            $task,
            AgentRunOutcome::STATUS_FAILED,
            null,
            'Model produced no parseable plan.',
        );

        $this->assertSame(AgentRunOutcome::STATUS_FAILED, $outcome->status);
        $this->assertSame(AgentRunOutcome::VERDICT_UNVERIFIED, $outcome->verdict);
        $this->assertSame('none', $outcome->verdict_source);
        $this->assertSame('Model produced no parseable plan.', $outcome->failure_reason);
    }

    public function test_tools_used_are_captured_from_chat_tool_calls(): void
    {
        $conversation = Conversation::factory()->create();

        $chatOutcome = $this->recorder->recordChatRun(
            $conversation,
            'run the tests',
            AgentRunOutcome::STATUS_COMPLETED,
            'done',
            [['name' => 'shell'], ['name' => 'web'], ['name' => 'shell']],
        );

        $this->assertSame(['shell', 'web'], $chatOutcome->tools_used);
    }

    public function test_task_tool_usage_is_left_unrecorded_rather_than_guessed(): void
    {
        $task = Task::factory()->create();
        TaskStep::factory()->for($task)->create(['description' => 'run the suite']);

        $outcome = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_COMPLETED, ['status' => 'passed']);

        $this->assertNull($outcome->tools_used);
    }

    public function test_role_profile_is_scoped_so_each_employee_owns_its_own_outcomes(): void
    {
        app(RoleProfileService::class)->ensureDefaults();

        $profile = AgentRoleProfile::query()->where('slug', 'security_auditor')->firstOrFail();
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);

        $outcome = $this->recorder->recordChatRun($conversation, 'audit auth', AgentRunOutcome::STATUS_COMPLETED, 'ok');

        $this->assertSame($profile->id, $outcome->role_profile_id);
    }

    public function test_a_task_outcome_inherits_its_employee_from_the_conversation(): void
    {
        app(RoleProfileService::class)->ensureDefaults();

        $profile = AgentRoleProfile::query()->where('slug', 'devops_operator')->firstOrFail();
        $conversation = Conversation::factory()->create(['role_profile_id' => $profile->id]);
        $task = Task::factory()->for($conversation)->create();

        $outcome = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_COMPLETED, ['status' => 'passed']);

        $this->assertSame($profile->id, $outcome->role_profile_id);
    }

    public function test_long_summaries_are_truncated_rather_than_stored_whole(): void
    {
        $conversation = Conversation::factory()->create();

        $outcome = $this->recorder->recordChatRun(
            $conversation,
            'long answer',
            AgentRunOutcome::STATUS_COMPLETED,
            str_repeat('a', 5000),
        );

        $this->assertNotNull($outcome->summary);
        $this->assertLessThan(1100, mb_strlen((string) $outcome->summary));
    }

    public function test_missing_keys_and_empty_summaries_are_normalised(): void
    {
        $conversation = Conversation::factory()->create();
        $task = Task::factory()->create();

        $passed = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_COMPLETED, ['status' => 'passed', 'missing' => []]);
        $failed = $this->recorder->recordTask($task, AgentRunOutcome::STATUS_FAILED, ['status' => 'failed', 'missing' => '']);

        $this->assertNull($passed->failure_type);
        $this->assertSame('unverified_incomplete', $failed->failure_type);
        $this->assertSame([], $this->recorder->recordChatRun($conversation, 'x', AgentRunOutcome::STATUS_COMPLETED, '')->tools_used);
    }
}
