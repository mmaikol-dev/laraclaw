<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DockerTool extends BaseTool
{
    public function getName(): string
    {
        return 'docker';
    }

    public function getDescription(): string
    {
        return 'Manage Docker on the host server: list and inspect containers, start/stop/restart/remove them, read their logs and live stats, list and pull images, run new containers, and check container health. Use this to host and supervise the sites we run. Actions: ps, inspect, start, stop, restart, remove, logs, stats, images, pull, run, health. Pass the container name or ID (and image for pull/run) as needed.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['ps', 'inspect', 'start', 'stop', 'restart', 'remove', 'logs', 'stats', 'images', 'pull', 'run', 'health'],
                    'description' => 'The Docker operation to perform.',
                ],
                'container' => ['type' => 'string', 'description' => 'Container name or ID (inspect, start, stop, restart, remove, logs, stats, health).'],
                'all' => ['type' => 'boolean', 'description' => 'ps: include stopped containers (default true).'],
                'tail' => ['type' => 'integer', 'description' => 'logs: number of trailing lines to return (default 200, max 2000).'],
                'image' => ['type' => 'string', 'description' => 'Image reference (pull, run), e.g. nginx:latest.'],
                'name' => ['type' => 'string', 'description' => 'run: container name (defaults to a generated name).'],
                'ports' => ['type' => 'string', 'description' => 'run: comma-separated host:container port mappings, e.g. 8080:80,443:443.'],
                'env' => ['type' => 'string', 'description' => 'run: comma-separated KEY=value environment entries.'],
                'volumes' => ['type' => 'string', 'description' => 'run: comma-separated host:container volume mappings, e.g. /srv/site:/usr/share/nginx/html.'],
                'restart_policy' => ['type' => 'string', 'description' => 'run: restart policy such as no, on-failure, always, or unless-stopped (default unless-stopped).'],
                'command' => ['type' => 'string', 'description' => 'run: optional command to execute inside the container.'],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_docker', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'ps' => $this->listContainers((bool) ($arguments['all'] ?? true)),
            'inspect' => $this->inspect($this->container($arguments)),
            'start' => $this->simpleAction('start', $this->container($arguments)),
            'stop' => $this->simpleAction('stop', $this->container($arguments)),
            'restart' => $this->simpleAction('restart', $this->container($arguments)),
            'remove' => $this->remove($this->container($arguments)),
            'logs' => $this->logs($this->container($arguments), $this->tail($arguments)),
            'stats' => $this->stats($this->string($arguments, 'container')),
            'images' => $this->listImages(),
            'pull' => $this->pull($this->required($arguments, 'image')),
            'run' => $this->run($arguments),
            'health' => $this->health($this->container($arguments)),
            default => throw new RuntimeException('Unsupported docker action. Use one of: ps, inspect, start, stop, restart, remove, logs, stats, images, pull, run, health.'),
        };
    }

    private function listContainers(bool $all): string
    {
        $args = ['ps', '--format', '{{json .}}'];

        if ($all) {
            $args[] = '--all';
        }

        $output = $this->docker($args);
        $rows = $this->decodeLines($output);

        if ($rows === []) {
            return 'No containers found.';
        }

        $lines = collect($rows)->map(fn (array $row): string => $this->formatContainer($row))->implode("\n");

        return $this->truncate($lines, 200);
    }

    private function inspect(string $container): string
    {
        $output = $this->docker(['inspect', $container]);

        return $this->truncate($output, 200);
    }

    private function simpleAction(string $action, string $container): string
    {
        return $this->docker([$action, $container]);
    }

    private function remove(string $container): string
    {
        return $this->docker(['rm', '--force', $container]);
    }

    private function logs(string $container, int $tail): string
    {
        $output = $this->docker(['logs', '--tail', (string) $tail, $container]);

        return $this->truncate($output, 500);
    }

    private function stats(string $container): string
    {
        $args = ['stats', '--no-stream', '--format', '{{json .}}'];

        if ($container !== '') {
            $args[] = $container;
        }

        $output = $this->docker($args);
        $rows = $this->decodeLines($output);

        if ($rows === []) {
            return 'No stats available.';
        }

        $lines = collect($rows)->map(function (array $row): string {
            $name = $row['Name'] ?? $row['Container'] ?? '?';
            $cpu = $row['CPUPerc'] ?? '?';
            $mem = $row['MemUsage'] ?? '?';
            $net = $row['NetIO'] ?? '?';

            return "{$name}: CPU {$cpu} | Mem {$mem} | Net {$net}";
        })->implode("\n");

        return $this->truncate($lines, 100);
    }

    private function listImages(): string
    {
        $output = $this->docker(['images', '--format', '{{json .}}']);
        $rows = $this->decodeLines($output);

        if ($rows === []) {
            return 'No images found.';
        }

        $lines = collect($rows)->map(function (array $row): string {
            $repository = $row['Repository'] ?? '?';
            $tag = $row['Tag'] ?? '?';
            $id = (string) ($row['ID'] ?? '?');
            $id = str_contains($id, ':') ? explode(':', $id, 2)[1] : $id;
            $id = substr($id, 0, 12);
            $size = $row['Size'] ?? '?';

            return "{$repository}:{$tag} ({$id}, {$size})";
        })->implode("\n");

        return $this->truncate($lines, 200);
    }

    private function pull(string $image): string
    {
        return $this->truncate($this->docker(['pull', $image]), 100);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function run(array $arguments): string
    {
        $image = $this->required($arguments, 'image');

        $args = ['run', '--detach', '--restart', $this->string($arguments, 'restart_policy') ?: 'unless-stopped'];

        $name = $this->string($arguments, 'name');

        if ($name !== '') {
            $args[] = '--name';
            $args[] = $name;
        }

        foreach ($this->csv($arguments, 'ports') as $port) {
            $args[] = '--publish';
            $args[] = $port;
        }

        foreach ($this->csv($arguments, 'env') as $env) {
            $args[] = '--env';
            $args[] = $env;
        }

        foreach ($this->csv($arguments, 'volumes') as $volume) {
            $args[] = '--volume';
            $args[] = $volume;
        }

        $args[] = $image;

        $command = $this->string($arguments, 'command');

        if ($command !== '') {
            foreach (preg_split('/\s+/', $command) ?: [] as $part) {
                if ($part !== '') {
                    $args[] = $part;
                }
            }
        }

        return $this->truncate($this->docker($args), 100);
    }

    private function health(string $container): string
    {
        $output = $this->docker(['inspect', '--format', '{{json .State}}', $container]);
        $state = json_decode($output, true);

        if (! is_array($state)) {
            return $this->truncate($output, 100);
        }

        $status = $state['Status'] ?? 'unknown';
        $running = ! empty($state['Running']) ? 'yes' : 'no';
        $health = $state['Health']['Status'] ?? null;
        $started = $state['StartedAt'] ?? '';

        $summary = "Status: {$status} | Running: {$running}";

        if (is_string($health) && $health !== '') {
            $summary .= " | Health: {$health}";
        }

        if ($started !== '') {
            $summary .= " | Started: {$started}";
        }

        return $summary;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function docker(array $args): string
    {
        $command = array_merge([(string) config('services.docker.binary', 'docker')], $args);
        $timeout = max(5, (int) config('services.docker.timeout', 60));

        $process = Process::timeout($timeout);

        $host = (string) config('services.docker.host', '');

        if ($host !== '') {
            $process = $process->env(['DOCKER_HOST' => $host]);
        }

        $result = $process->run($command);
        $output = trim($result->output());
        $error = trim($result->errorOutput());

        if (! $result->successful()) {
            $message = $error !== '' ? $error : ($output !== '' ? $output : 'unknown error');

            throw new RuntimeException('Docker command failed: '.$message);
        }

        if ($error !== '') {
            $output = trim($output."\n[stderr]\n".$error);
        }

        return $output === '' ? '(no output)' : $output;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeLines(string $output): array
    {
        return collect(preg_split('/\r\n|\n|\r/', trim($output)) ?: [])
            ->map(fn (string $line): ?array => json_decode(trim($line), true))
            ->filter(fn ($row): bool => is_array($row))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatContainer(array $row): string
    {
        $name = $row['Names'] ?? $row['Name'] ?? '?';
        $state = $row['State'] ?? $row['Status'] ?? '?';
        $image = $row['Image'] ?? '?';
        $ports = $row['Ports'] ?? '';

        $line = "{$name} [{$state}] {$image}";

        if ($ports !== '') {
            $line .= " | {$ports}";
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function container(array $arguments): string
    {
        return $this->required($arguments, 'container');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function required(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("A {$key} is required for this docker action.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function string(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private function csv(array $arguments, string $key): array
    {
        $raw = $this->string($arguments, $key);

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function tail(array $arguments): int
    {
        $tail = (int) ($arguments['tail'] ?? 200);

        return max(1, min(2000, $tail));
    }
}
