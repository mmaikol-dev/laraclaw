<?php

namespace Tests\Unit;

use App\Services\Agent\ToolRegistry;
use App\Services\Tools\ShellTool;
use RuntimeException;
use Tests\TestCase;

class ShellToolStreamingTest extends TestCase
{
    public function test_streaming_returns_same_final_output_as_blocking_execution(): void
    {
        $tool = new ShellTool;
        $arguments = ['command' => 'printf "hello %s" world'];

        $blocking = $tool->execute($arguments);

        $chunks = [];
        $streamed = $tool->executeStreaming($arguments, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $this->assertSame(trim($blocking), trim($streamed));
        $this->assertStringContainsString('hello world', $streamed);
    }

    public function test_output_chunks_arrive_before_command_finishes(): void
    {
        $tool = new ShellTool;

        $chunkTimes = [];
        $startedAt = microtime(true);

        $output = $tool->executeStreaming(
            ['command' => 'echo first; sleep 0.6; echo second', 'timeout' => 10],
            function () use (&$chunkTimes, $startedAt): void {
                $chunkTimes[] = microtime(true) - $startedAt;
            },
        );

        $this->assertGreaterThanOrEqual(2, count($chunkTimes));
        $this->assertLessThan(0.4, $chunkTimes[0], 'First chunk should arrive before the sleep finishes (not buffered until exit).');
        $this->assertGreaterThan(0.5, end($chunkTimes), 'Last chunk should arrive after the sleep.');
        $this->assertStringContainsString('first', $output);
        $this->assertStringContainsString('second', $output);
    }

    public function test_stderr_is_streamed_with_prefix_and_included_in_final_output(): void
    {
        $tool = new ShellTool;

        $chunks = [];
        $output = $tool->executeStreaming(
            ['command' => 'echo out; echo err 1>&2'],
            function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        $stderrChunks = array_filter($chunks, fn (string $chunk): bool => str_contains($chunk, '[stderr]'));
        $this->assertNotEmpty($stderrChunks);
        $this->assertStringContainsString('[stderr]', $output);
        $this->assertStringContainsString('err', $output);
    }

    public function test_timeout_and_exit_code_notes_are_appended(): void
    {
        $tool = new ShellTool;

        $timedOut = $tool->executeStreaming(['command' => 'sleep 5', 'timeout' => 1], fn (): string => '');
        $this->assertStringContainsString('timed out after 1 seconds', $timedOut);

        $failed = $tool->executeStreaming(['command' => 'exit 3'], fn (): string => '');
        $this->assertStringContainsString('[Exit code: 3]', $failed);
    }

    public function test_blocked_commands_throw_in_streaming_mode(): void
    {
        $tool = new ShellTool;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Blocked shell pattern');

        $tool->executeStreaming(['command' => 'sudo rm -rf /'], fn (): string => '');
    }

    public function test_registry_passes_streaming_callback_to_capable_tools(): void
    {
        $tool = new ShellTool;
        $received = [];

        $result = app(ToolRegistry::class)->execute('shell', [
            'command' => 'echo streamed-line',
        ], function (string $chunk) use (&$received): void {
            $received[] = $chunk;
        });

        $this->assertNull($result['error']);
        $this->assertNotSame([], $received, 'Registry should invoke the streaming callback for tools that support it.');
        $this->assertStringContainsString('streamed-line', $result['output']);
    }
}
