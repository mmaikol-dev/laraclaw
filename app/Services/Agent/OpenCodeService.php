<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class OpenCodeService
{
    /**
     * Resolve the opencode binary path.
     *
     * An explicitly configured path is always honoured (even if missing, so
     * misconfiguration is visible); otherwise the default install location
     * under the user's home directory is used.
     */
    public function binaryPath(): string
    {
        $configured = (string) config('agent.opencode_binary', '');

        if ($configured !== '') {
            return $configured;
        }

        $home = (string) (getenv('HOME') ?: config('agent.home_dir', '/tmp'));

        return rtrim($home, '/').'/'.trim((string) config('agent.opencode_binary_fallback', '.opencode/bin/opencode'), '/');
    }

    public function isAvailable(): bool
    {
        return file_exists($this->binaryPath());
    }

    /**
     * List model IDs available to opencode.
     *
     * @return array<int, string>
     */
    public function models(): array
    {
        $result = Process::timeout(30)
            ->run([$this->binaryPath(), 'models']);

        if (! $result->successful()) {
            throw new RuntimeException('Failed to list opencode models: '.trim($result->errorOutput() ?: $result->output()));
        }

        return collect(preg_split("/\r\n|\n|\r/", trim($result->output())) ?: [])
            ->map(fn (string $line): string => trim(explode("\t", $line)[0]))
            ->filter(fn (string $line): bool => $line !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build a long-running `opencode run` command for a delegated coding task.
     */
    public function runCommand(string $model, string $task): array
    {
        return [$this->binaryPath(), 'run', '--model', $model, $task];
    }
}
