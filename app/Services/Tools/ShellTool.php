<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use RuntimeException;

class ShellTool extends BaseTool implements StreamsOutput
{
    public function getName(): string
    {
        return 'shell';
    }

    public function getDescription(): string
    {
        return 'Execute Linux shell commands with safety checks, a timeout, and controlled output truncation.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string'],
                'working_dir' => ['type' => 'string'],
                'timeout' => ['type' => 'integer'],
            ],
            'required' => ['command'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_shell', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        [$command, $workingDirectory, $timeout] = $this->prepare($arguments);

        $wrappedCommand = 'timeout '.$timeout.' bash -c '.escapeshellarg($command);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($wrappedCommand, $descriptors, $pipes, $workingDirectory, $this->buildEnvironment());

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the shell process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim($stdout);

        if ($stderr !== '') {
            $output = trim($output."\n[stderr]\n".trim($stderr));
        }

        return $this->finalizeOutput($output, $exitCode, $timeout);
    }

    /**
     * Execute while streaming output chunks to a callback as they arrive.
     *
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): void  $onOutput
     */
    public function executeStreaming(array $arguments, callable $onOutput): string
    {
        [$command, $workingDirectory, $timeout] = $this->prepare($arguments);

        $wrappedCommand = 'timeout '.$timeout.' bash -c '.escapeshellarg($command);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->buildEnvironment();

        $process = proc_open($wrappedCommand, $descriptors, $pipes, $workingDirectory, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the shell process.');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $stdoutOpen = true;
        $stderrOpen = true;

        while ($stdoutOpen || $stderrOpen) {
            if ($stdoutOpen) {
                $chunk = fread($pipes[1], 8192);

                if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                    $stdoutOpen = false;
                    fclose($pipes[1]);
                } elseif ($chunk !== '') {
                    $stdout .= $chunk;
                    $onOutput($chunk);
                }
            }

            if ($stderrOpen) {
                $chunk = fread($pipes[2], 8192);

                if ($chunk === false || ($chunk === '' && feof($pipes[2]))) {
                    $stderrOpen = false;
                    fclose($pipes[2]);
                } elseif ($chunk !== '') {
                    $stderr .= $chunk;
                    $onOutput('[stderr] '.$chunk);
                }
            }

            if ($stdoutOpen || $stderrOpen) {
                usleep(50_000);
            }
        }

        $exitCode = proc_close($process);
        $output = trim($stdout);

        if (trim($stderr) !== '') {
            $output = trim($output."\n[stderr]\n".trim($stderr));
        }

        return $this->finalizeOutput($output, $exitCode, $timeout);
    }

    /**
     * Validate arguments and resolve command, working directory, and timeout.
     *
     * OpenCode delegation commands are long-running by nature (whole coding
     * tasks), so they are exempt from the interactive shell cap and may run
     * for up to agent.opencode_timeout seconds.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: string, 2: int}
     */
    private function prepare(array $arguments): array
    {
        $command = trim((string) ($arguments['command'] ?? ''));

        if ($command === '') {
            throw new RuntimeException('A command is required.');
        }

        $this->guardCommand($command);

        $isOpencodeCommand = self::isOpenCodeCommand($command);
        $timeout = self::resolveTimeout($command, isset($arguments['timeout']) ? (int) $arguments['timeout'] : null);
        $workingDirectory = (string) ($arguments['working_dir'] ?? AgentSetting::get('working_dir', '/tmp'));

        if (! is_dir($workingDirectory)) {
            if (! @mkdir($workingDirectory, 0755, true) || ! is_dir($workingDirectory)) {
                $workingDirectory = '/tmp';
            }
        }

        return [$command, $workingDirectory, $timeout];
    }

    /**
     * Resolve the effective timeout for a command.
     *
     * OpenCode delegation commands are long-running by nature (whole coding
     * tasks), so they are exempt from the interactive 60-second shell cap and
     * may run up to agent.opencode_timeout seconds.
     */
    public static function resolveTimeout(string $command, ?int $requested = null): int
    {
        if (self::isOpenCodeCommand($command)) {
            $ceiling = max(60, (int) config('agent.opencode_timeout', 3600));

            return min($ceiling, max(1, $requested ?? $ceiling));
        }

        return min(60, max(1, $requested ?? (int) AgentSetting::get('shell_timeout', 30)));
    }

    /**
     * Determine whether a command invokes the opencode CLI.
     */
    public static function isOpenCodeCommand(string $command): bool
    {
        return preg_match('/(?:^|[\s\/"\'=])opencode(?:[\s"\']|$)/', trim($command)) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvironment(): array
    {
        $display = getenv('DISPLAY') ?: ':0';
        $home = getenv('HOME') ?: ('/home/'.get_current_user());

        return array_merge(getenv() ?: [], [
            'DISPLAY' => $display,
            'XAUTHORITY' => getenv('XAUTHORITY') ?: ($home.'/.Xauthority'),
            'DBUS_SESSION_BUS_ADDRESS' => getenv('DBUS_SESSION_BUS_ADDRESS') ?: ('unix:path=/run/user/'.posix_getuid().'/bus'),
            'HOME' => $home,
        ]);
    }

    private function finalizeOutput(string $output, int $exitCode, int $timeout): string
    {
        if ($output === '') {
            $output = '(no output)';
        }

        if ($exitCode === 124) {
            $output .= "\n[Command timed out after {$timeout} seconds]";
        } elseif ($exitCode !== 0) {
            $output .= "\n[Exit code: {$exitCode}]";
        }

        return $this->truncate($output, (int) AgentSetting::get('max_output_lines', 500));
    }

    private function guardCommand(string $command): void
    {
        $blockedPatterns = [
            'sudo',
            'su ',
            'passwd',
            'useradd',
            'userdel',
            'usermod',
            'visudo',
            'chmod 777',
            'rm -rf /',
            'mkfs',
            'fdisk',
            'dd if=',
            'shutdown',
            'reboot',
            'halt',
            'poweroff',
            'init 0',
            'iptables',
            'ufw',
            'systemctl enable',
            'systemctl disable',
            'crontab',
            '| bash',
            '| sh',
            'bash <(',
            'curl | bash',
            'wget | bash',
        ];

        $normalized = strtolower($command);

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                throw new RuntimeException("Blocked shell pattern detected: {$pattern}");
            }
        }
    }
}
