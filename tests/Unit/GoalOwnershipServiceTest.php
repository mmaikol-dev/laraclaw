<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\TaskLog;
use App\Services\Agent\GoalOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoalOwnershipServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_starts_a_run_with_completion_criteria_and_resume_state(): void
    {
        $conversation = Conversation::query()->create([
            'title' => 'New conversation',
        ]);

        $service = new GoalOwnershipService;
        $service->beginRun($conversation, 'Fix the queue worker and verify logs.', 'DevOps Operator');

        $conversation->refresh();

        $this->assertSame('DevOps Operator', $conversation->identity_label);
        $this->assertSame('Fix the queue worker and verify logs.', $conversation->active_goal);
        $this->assertSame('in_progress', $conversation->verification_status);
        $this->assertIsArray($conversation->completion_criteria);
        $this->assertNotEmpty($conversation->completion_criteria);
        $this->assertNotNull($conversation->last_resumed_at);
    }

    public function test_it_marks_verified_runs_when_successful_tool_evidence_exists(): void
    {
        $conversation = Conversation::query()->create([
            'title' => 'Queue fix',
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'I checked the logs and verified the queue worker is healthy.',
        ]);

        TaskLog::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'tool_name' => 'shell',
            'tool_input' => ['command' => 'tail -20 queue.log'],
            'tool_output' => 'worker healthy',
            'status' => 'success',
            'duration_ms' => 12,
        ]);

        $service = new GoalOwnershipService;
        $service->finalizeRun($conversation, $message);

        $conversation->refresh();

        $this->assertSame('verified', $conversation->verification_status);
        $this->assertNull($conversation->next_action);
        $this->assertSame(['shell'], $conversation->resumable_state['recent_tools']);
        $this->assertNotNull($conversation->last_verified_at);
    }
}
