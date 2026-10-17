<?php

namespace Tests\Unit;

use App\Services\Agent\ToolRegistry;
use App\Services\Tools\DockerTool;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class DockerToolTest extends TestCase
{
    public function test_the_docker_tool_is_registered(): void
    {
        $this->assertInstanceOf(DockerTool::class, app(ToolRegistry::class)->get('docker'));
    }

    public function test_it_lists_containers(): void
    {
        Process::fake([
            '*' => Process::result(output: implode("\n", [
                '{"ID":"abc123","Names":"web","State":"running","Image":"nginx:latest","Ports":"0.0.0.0:80->80/tcp"}',
                '{"ID":"def456","Names":"db","State":"exited","Image":"postgres:16"}',
            ])),
        ]);

        $result = (new DockerTool)->execute(['action' => 'ps']);

        $this->assertStringContainsString('web [running] nginx:latest', $result);
        $this->assertStringContainsString('db [exited] postgres:16', $result);

        Process::assertRan(fn (PendingProcess $process): bool => $this->command($process) === 'docker ps --format {{json .}} --all');
    }

    public function test_it_lists_images(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"ID":"sha256:abcdef012345","Repository":"nginx","Tag":"latest","Size":"187MB"}'),
        ]);

        $result = (new DockerTool)->execute(['action' => 'images']);

        $this->assertStringContainsString('nginx:latest', $result);
        $this->assertStringContainsString('abcdef012345', $result);
    }

    public function test_it_runs_a_container_with_ports_env_and_volumes(): void
    {
        Process::fake(['*' => Process::result(output: 'container-id-123')]);

        (new DockerTool)->execute([
            'action' => 'run',
            'image' => 'nginx:latest',
            'name' => 'site-web',
            'ports' => '8080:80,443:443',
            'env' => 'APP_ENV=production,APP_KEY=secret',
            'volumes' => '/srv/site:/usr/share/nginx/html',
            'restart_policy' => 'always',
        ]);

        Process::assertRan(function (PendingProcess $process): bool {
            $command = $this->command($process);

            return str_starts_with($command, 'docker run --detach --restart always')
                && str_contains($command, '--name site-web')
                && str_contains($command, '--publish 8080:80')
                && str_contains($command, '--publish 443:443')
                && str_contains($command, '--env APP_ENV=production')
                && str_contains($command, '--volume /srv/site:/usr/share/nginx/html')
                && str_ends_with($command, ' nginx:latest');
        });
    }

    public function test_it_reads_container_logs_with_a_tail(): void
    {
        Process::fake(['*' => Process::result(output: 'line one')]);

        $result = (new DockerTool)->execute([
            'action' => 'logs',
            'container' => 'web',
            'tail' => 50,
        ]);

        $this->assertStringContainsString('line one', $result);

        Process::assertRan(fn (PendingProcess $process): bool => $this->command($process) === 'docker logs --tail 50 web');
    }

    public function test_it_reports_container_health(): void
    {
        Process::fake([
            '*' => Process::result(output: '{"Status":"running","Running":true,"StartedAt":"2026-01-01T00:00:00Z","Health":{"Status":"healthy"}}'),
        ]);

        $result = (new DockerTool)->execute([
            'action' => 'health',
            'container' => 'web',
        ]);

        $this->assertStringContainsString('Status: running', $result);
        $this->assertStringContainsString('Health: healthy', $result);
    }

    public function test_it_throws_when_docker_fails(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to the Docker daemon');

        (new DockerTool)->execute(['action' => 'ps']);
    }

    public function test_it_requires_a_container_name(): void
    {
        $this->expectException(RuntimeException::class);

        (new DockerTool)->execute(['action' => 'restart']);
    }

    public function test_it_passes_the_configured_docker_host(): void
    {
        config()->set('services.docker.host', 'tcp://10.0.0.5:2376');

        Process::fake(['*' => Process::result(output: 'ok')]);

        (new DockerTool)->execute(['action' => 'ps']);

        Process::assertRan(fn (PendingProcess $process): bool => ($process->environment['DOCKER_HOST'] ?? null) === 'tcp://10.0.0.5:2376');
    }

    private function command(PendingProcess $process): string
    {
        return is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
    }
}
