<?php

namespace Tests\Unit;

use App\Events\AgentChunkStreamed;
use App\Events\AgentFinished;
use App\Events\AgentToolCalled;
use App\Jobs\RunAgentJob;
use Illuminate\Broadcasting\Channel;
use PHPUnit\Framework\TestCase;

class AgentBroadcastingTest extends TestCase
{
    public function test_agent_chunk_event_exposes_expected_broadcast_metadata(): void
    {
        $event = new AgentChunkStreamed('conversation.5', 'hello');

        $this->assertInstanceOf(Channel::class, $event->broadcastOn());
        $this->assertSame('agent.chunk', $event->broadcastAs());
        $this->assertSame(['content' => 'hello'], $event->broadcastWith());
    }

    public function test_agent_tool_event_exposes_expected_payload(): void
    {
        $event = new AgentToolCalled('conversation.5', [
            'id' => 10,
            'tool_name' => 'local_stub',
            'input' => ['message' => 'hi'],
            'status' => 'running',
        ]);

        $this->assertSame('agent.tool', $event->broadcastAs());
        $this->assertSame([
            'id' => 10,
            'tool_name' => 'local_stub',
            'input' => ['message' => 'hi'],
            'status' => 'running',
        ], $event->broadcastWith());
    }

    public function test_agent_finished_event_exposes_message_id_and_stats(): void
    {
        $event = new AgentFinished('conversation.7', 12, [
            'completion_tokens' => 14,
        ]);

        $this->assertSame('agent.done', $event->broadcastAs());
        $this->assertSame([
            'message_id' => 12,
            'stats' => ['completion_tokens' => 14],
        ], $event->broadcastWith());
    }

    public function test_run_agent_job_uses_expected_defaults(): void
    {
        $job = new RunAgentJob(9, 'Hello');

        $this->assertSame(300, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertSame(9, $job->conversationId);
        $this->assertSame('Hello', $job->userMessage);
    }
}
