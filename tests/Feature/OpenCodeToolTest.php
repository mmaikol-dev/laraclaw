<?php

namespace Tests\Feature;

use App\Services\Agent\OpenCodeService;
use App\Services\Tools\OpenCodeTool;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class OpenCodeToolTest extends TestCase
{
    private string $fakeBinary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeBinary = tempnam(sys_get_temp_dir(), 'opencode-fixture-');
        file_put_contents($this->fakeBinary, "#!/bin/bash\nexit 0\n");
        chmod($this->fakeBinary, 0755);

        config(['agent.opencode_binary' => $this->fakeBinary]);
    }

    protected function tearDown(): void
    {
        @unlink($this->fakeBinary);

        parent::tearDown();
    }

    public function test_list_models_returns_available_model_ids(): void
    {
        Process::fake([
            '*' => Process::result(output: "opencode/big-pickle\nopencode/nemotron-free\nopencode/big-pickle\n"),
        ]);

        $tool = new OpenCodeTool(new OpenCodeService);
        $output = $tool->execute(['action' => 'list_models']);

        $this->assertStringContainsString('opencode/big-pickle', $output);
        $this->assertStringContainsString('opencode/nemotron-free', $output);
        $this->assertSame(1, substr_count($output, 'opencode/big-pickle'), 'Duplicate models should be de-duplicated.');
    }

    public function test_list_models_throws_when_cli_fails(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        $tool = new OpenCodeTool(new OpenCodeService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to list opencode models');

        $tool->execute(['action' => 'list_models']);
    }

    public function test_check_reports_missing_binary(): void
    {
        config(['agent.opencode_binary' => '/nonexistent/opencode']);

        $tool = new OpenCodeTool(new OpenCodeService);
        $output = $tool->execute(['action' => 'check']);

        $this->assertStringContainsString('NOT installed', $output);
    }

    public function test_run_requires_model_and_task(): void
    {
        $tool = new OpenCodeTool(new OpenCodeService);

        try {
            $tool->execute(['action' => 'run', 'model' => 'm']);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('run requires model and task', $e->getMessage());
        }
    }

    public function test_run_executes_long_running_task_and_reports_exit_code(): void
    {
        $dir = sys_get_temp_dir().'/opencode-run-test';

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        Process::fake([
            '*' => Process::result(output: "Refactored 3 files.\nTests pass."),
        ]);

        $tool = new OpenCodeTool(new OpenCodeService);
        $output = $tool->execute([
            'action' => 'run',
            'model' => 'opencode/big-pickle',
            'task' => 'Add a feature',
            'working_dir' => $dir,
        ]);

        $this->assertStringContainsString('Refactored 3 files.', $output);
        $this->assertStringNotContainsString('[opencode exited with code', $output);

        @rmdir($dir);
    }
}
