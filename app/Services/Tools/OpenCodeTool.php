<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use App\Services\Agent\OpenCodeService;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class OpenCodeTool extends BaseTool
{
    public function __construct(private readonly OpenCodeService $openCode) {}

    public function getName(): string
    {
        return 'opencode';
    }

    public function getDescription(): string
    {
        return 'Delegate coding work to the OpenCode CLI agent. List available models, check availability, and run long-running coding tasks (minutes to hours) that would exceed the normal shell timeout.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list_models', 'check', 'run'],
                    'description' => implode(' ', [
                        'list_models: show every model ID available to opencode.',
                        'check: verify the opencode binary is installed.',
                        'run: execute a delegated coding task — pass model and task; runs without the short shell timeout cap.',
                    ]),
                ],
                'model' => [
                    'type' => 'string',
                    'description' => implode(' ', [
                        'Model ID for run (from list_models).',
                        'If you are running interactively with the user available, ask which model to use.',
                        'If you are running autonomously (mission worker or scheduled task), NEVER ask — pick the strongest coding-capable model from list_models yourself and note the choice in your handoff.',
                    ]),
                ],
                'task' => [
                    'type' => 'string',
                    'description' => 'Full task description for run, including target project path.',
                ],
                'working_dir' => [
                    'type' => 'string',
                    'description' => 'Working directory for run (defaults to the configured agent working directory).',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Optional timeout in seconds for run (default/ceiling from config, typically 3600).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_opencode', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'list_models' => $this->listModels(),
            'check' => $this->check(),
            'run' => $this->run($arguments),
            default => throw new RuntimeException('Unsupported opencode action.'),
        };
    }

    private function listModels(): string
    {
        if (! $this->openCode->isAvailable()) {
            throw new RuntimeException('opencode is not installed at '.$this->openCode->binaryPath().'.');
        }

        $models = $this->openCode->models();

        if ($models === []) {
            return 'No models are currently available in opencode.';
        }

        return "Available opencode models:\n\n".implode("\n", array_map(fn (string $m): string => "  - {$m}", $models))
            ."\n\nAsk the user which model to use before running tasks.";
    }

    private function check(): string
    {
        if (! $this->openCode->isAvailable()) {
            return 'opencode is NOT installed (expected at '.$this->openCode->binaryPath().').';
        }

        return 'opencode is available at '.$this->openCode->binaryPath().'. Use list_models to see model options.';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function run(array $arguments): string
    {
        $model = trim((string) ($arguments['model'] ?? ''));
        $task = trim((string) ($arguments['task'] ?? ''));

        if ($model === '' || $task === '') {
            throw new RuntimeException('run requires model and task. Use list_models first if no model has been chosen.');
        }

        if (! $this->openCode->isAvailable()) {
            throw new RuntimeException('opencode is not installed at '.$this->openCode->binaryPath().'.');
        }

        $workingDirectory = (string) ($arguments['working_dir'] ?? AgentSetting::get('working_dir', config('agent.working_dir', '/tmp/laraclaw')));

        if (! is_dir($workingDirectory)) {
            throw new RuntimeException("Working directory '{$workingDirectory}' does not exist.");
        }

        $ceiling = max(60, (int) config('agent.opencode_timeout', 3600));
        $timeout = min($ceiling, max(60, (int) ($arguments['timeout'] ?? $ceiling)));

        $process = Process::command($this->openCode->runCommand($model, $task))
            ->path($workingDirectory)
            ->timeout($timeout)
            ->env(['HOME' => (string) (getenv('HOME') ?: config('agent.home_dir', '/tmp'))]);

        $result = $process->run();

        $output = trim($result->output());

        if (trim((string) $result->errorOutput()) !== '') {
            $output = trim($output."\n[stderr]\n".trim($result->errorOutput()));
        }

        if ($output === '') {
            $output = '(no output)';
        }

        if (! $result->successful()) {
            $exitCode = $result->exitCode() ?? -1;

            $output .= "\n[opencode exited with code {$exitCode}]".($exitCode === 124 || str_contains(strtolower((string) $result->errorOutput()), 'timeout') ? " (timed out after {$timeout} seconds)" : '');
        }

        return $this->truncate($output, (int) AgentSetting::get('max_output_lines', 500));
    }
}
