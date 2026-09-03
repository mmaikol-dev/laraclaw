<?php

namespace Tests\Unit;

use App\Services\Tools\ShellTool;
use Tests\TestCase;

class ShellTimeoutTest extends TestCase
{
    public function test_regular_commands_are_capped_at_60_seconds(): void
    {
        $this->assertSame(30, ShellTool::resolveTimeout('ls -la', null));
        $this->assertSame(60, ShellTool::resolveTimeout('npm run build', 120));
        $this->assertSame(5, ShellTool::resolveTimeout('sleep 10', 5));
    }

    public function test_opencode_commands_get_long_running_window(): void
    {
        config(['agent.opencode_timeout' => 3600]);

        $command = '$HOME/.opencode/bin/opencode run --model m "build feature"';

        $this->assertSame(3600, ShellTool::resolveTimeout($command, null), 'No explicit timeout should default to the long window.');
        $this->assertSame(3600, ShellTool::resolveTimeout($command, 7200), 'Explicit timeout above the configurable ceiling is capped.');
        $this->assertSame(600, ShellTool::resolveTimeout($command, 600));
    }

    public function test_opencode_detection_matches_invocations_only(): void
    {
        $this->assertTrue(ShellTool::isOpenCodeCommand('opencode models'));
        $this->assertTrue(ShellTool::isOpenCodeCommand('$HOME/.opencode/bin/opencode run --model m "task"'));
        $this->assertFalse(ShellTool::isOpenCodeCommand('cat opencode-notes.md'));
        $this->assertFalse(ShellTool::isOpenCodeCommand('echo "opencoded"'));
        $this->assertFalse(ShellTool::isOpenCodeCommand('ls -la'));
    }
}
