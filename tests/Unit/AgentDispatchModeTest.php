<?php

namespace Tests\Unit;

use App\Services\Agent\AgentDispatchMode;
use PHPUnit\Framework\TestCase;

class AgentDispatchModeTest extends TestCase
{
    public function test_it_prefers_after_response_mode_for_local_environment(): void
    {
        $dispatchMode = new AgentDispatchMode();

        $this->assertSame('after_response', $dispatchMode->resolve('local', 'database'));
    }

    public function test_it_prefers_after_response_mode_for_sync_queue_connections(): void
    {
        $dispatchMode = new AgentDispatchMode();

        $this->assertSame('after_response', $dispatchMode->resolve('production', 'sync'));
    }

    public function test_it_uses_queued_mode_for_non_local_async_connections(): void
    {
        $dispatchMode = new AgentDispatchMode();

        $this->assertSame('queued', $dispatchMode->resolve('production', 'database'));
    }
}
