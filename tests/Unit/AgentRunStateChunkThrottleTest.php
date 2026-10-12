<?php

namespace Tests\Unit;

use App\Services\Agent\AgentRunState;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

class CountingRepository extends Repository
{
    public int $puts = 0;

    public function put($key, $value, $seconds = 60)
    {
        $this->puts++;

        return parent::put($key, $value, $seconds);
    }
}

class AgentRunStateChunkThrottleTest extends TestCase
{
    public function test_it_does_not_write_the_cache_on_every_streamed_chunk(): void
    {
        $cache = new CountingRepository(new ArrayStore);
        $state = new AgentRunState($cache);

        $state->begin(11);
        $cache->puts = 0;

        for ($i = 0; $i < 500; $i++) {
            $state->appendChunk(11, 'x');
        }

        // 500 chunks must not mean 500 cache writes (each was a full state
        // read + re-serialise against the database cache driver).
        $this->assertLessThan(20, $cache->puts, 'Streamed chunks were not throttled.');
    }

    public function test_buffered_chunks_are_not_lost_when_the_run_finishes(): void
    {
        $cache = new CountingRepository(new ArrayStore);
        $state = new AgentRunState($cache);

        $state->begin(12);
        $state->appendChunk(12, 'Hello ');
        $state->appendChunk(12, 'brave ');
        $state->appendChunk(12, 'world');
        $state->finish(12, 99, ['prompt_tokens' => 5]);

        $current = $state->get(12);

        $this->assertSame('Hello brave world', $current['draft']);
        $this->assertSame('completed', $current['status']);
        $this->assertSame(99, $current['message_id']);
    }

    public function test_buffered_chunks_survive_an_interleaved_tool_write(): void
    {
        $cache = new CountingRepository(new ArrayStore);
        $state = new AgentRunState($cache);

        $state->begin(13);
        $state->appendChunk(13, 'partial answer');

        // A tool record between chunks must not overwrite the buffered draft.
        $state->recordTool(13, [
            'id' => 4,
            'tool_name' => 'file',
            'status' => 'running',
            'input' => ['path' => '/tmp/demo.txt'],
        ]);

        $state->finish(13, 100, []);

        $current = $state->get(13);

        $this->assertSame('partial answer', $current['draft']);
        $this->assertCount(1, $current['tools']);
    }

    public function test_buffered_chunks_survive_a_cancellation(): void
    {
        $cache = new CountingRepository(new ArrayStore);
        $state = new AgentRunState($cache);

        $state->begin(14);
        $state->appendChunk(14, 'stopped mid stream');
        $state->cancel(14);

        $this->assertStringContainsString('stopped mid stream', $state->get(14)['draft']);
        $this->assertSame('error', $state->get(14)['status']);
    }

    public function test_starting_a_new_run_discards_stale_buffered_chunks(): void
    {
        $cache = new CountingRepository(new ArrayStore);
        $state = new AgentRunState($cache);

        $state->begin(15);
        $state->appendChunk(15, 'from the aborted run');

        $state->begin(15);
        $state->finish(15, 101, []);

        $this->assertSame('', $state->get(15)['draft']);
    }
}
