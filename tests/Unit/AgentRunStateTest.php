<?php

namespace Tests\Unit;

use App\Services\Agent\AgentRunState;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class AgentRunStateTest extends TestCase
{
    public function test_it_tracks_the_live_run_state_for_a_conversation(): void
    {
        $state = new AgentRunState(new Repository(new ArrayStore()));

        $state->begin(7);
        $state->markThinking(7);
        $state->appendChunk(7, 'Hello');
        $state->recordTool(7, [
            'id' => 12,
            'tool_name' => 'file',
            'status' => 'running',
            'input' => ['path' => '/tmp/demo.txt'],
        ]);
        $state->finish(7, 42, ['prompt_tokens' => 10]);

        $current = $state->get(7);

        $this->assertSame('completed', $current['status']);
        $this->assertSame('Hello', $current['draft']);
        $this->assertSame(42, $current['message_id']);
        $this->assertCount(1, $current['tools']);
        $this->assertSame('file', $current['tools'][0]['tool_name']);
        $this->assertNotEmpty($current['steps']);
    }
}
