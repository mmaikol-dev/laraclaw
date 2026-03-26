<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use RuntimeException;

class ShellTool extends BaseTool
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
        $command = trim((string) ($arguments['command'] ?? ''));

        if ($command === '') {
            throw new RuntimeException('A command is required.');
        }

        $this->guardCommand($command);

        $timeout = min(60, max(1, (int) ($arguments['timeout'] ?? AgentSetting::get('shell_timeout', 30))));
        $workingDirectory = (string) ($arguments['working_dir'] ?? AgentSetting::get('working_dir', '/tmp/laraclaw'));

        if (! is_dir($workingDirectory)) {
            throw new RuntimeException('The working directory does not exist.');
        }

        $wrappedCommand = 'timeout '.$timeout.' bash -c '.escapeshellarg($command);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($wrappedCommand, $descriptors, $pipes, $workingDirectory);

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
            'nohup',
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
