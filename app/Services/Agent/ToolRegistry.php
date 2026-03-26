<?php

namespace App\Services\Agent;

use App\Services\Tools\BaseTool;

class ToolRegistry
{
    /**
     * @var array<string, BaseTool>
     */
    private array $tools = [];

    public function register(BaseTool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?BaseTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, BaseTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOllamaTools(): array
    {
        return collect($this->tools)
            ->filter(fn (BaseTool $tool): bool => $tool->isEnabled())
            ->map(fn (BaseTool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{output: string, error: string|null, duration_ms: int}
     */
    public function execute(string $toolName, array $arguments): array
    {
        $tool = $this->get($toolName);

        if ($tool === null) {
            return [
                'output' => '',
                'error' => "Tool [{$toolName}] is not registered.",
                'duration_ms' => 0,
            ];
        }

        if (! $tool->isEnabled()) {
            return [
                'output' => '',
                'error' => "Tool [{$toolName}] is currently disabled.",
                'duration_ms' => 0,
            ];
        }

        $startedAt = microtime(true);

        try {
            return [
                'output' => $tool->execute($arguments),
                'error' => null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (\Throwable $exception) {
            return [
                'output' => '',
                'error' => $exception->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }
}
