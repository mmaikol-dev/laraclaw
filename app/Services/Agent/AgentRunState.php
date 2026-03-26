<?php

namespace App\Services\Agent;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

class AgentRunState
{
    public function __construct(
        protected Repository $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function begin(int $conversationId): array
    {
        $state = [
            'conversation_id' => $conversationId,
            'status' => 'queued',
            'draft' => '',
            'steps' => [$this->step('queued', 'Queued your request.')],
            'tools' => [],
            'message_id' => null,
            'stats' => null,
            'updated_at' => now()->toISOString(),
        ];

        $this->put($conversationId, $state);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function markThinking(int $conversationId, string $label = 'Thinking through your request.'): array
    {
        return $this->mutate($conversationId, function (array $state) use ($label): array {
            $state['status'] = 'thinking';
            $state['steps'] = $this->appendStep($state['steps'] ?? [], $this->step('thinking', $label));

            return $state;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function appendChunk(int $conversationId, string $chunk): array
    {
        return $this->mutate($conversationId, function (array $state) use ($chunk): array {
            $state['status'] = 'responding';
            $state['draft'] = (($state['draft'] ?? '').$chunk);

            if (! $this->hasStepType($state['steps'] ?? [], 'responding')) {
                $state['steps'] = $this->appendStep($state['steps'] ?? [], $this->step('responding', 'Streaming reply.'));
            }

            return $state;
        });
    }

    /**
     * @param  array{id: int, tool_name: string, input?: array<string, mixed>, output?: string|null, status: string, duration_ms?: int}  $toolData
     * @return array<string, mixed>
     */
    public function recordTool(int $conversationId, array $toolData): array
    {
        return $this->mutate($conversationId, function (array $state) use ($toolData): array {
            $tool = [
                'id' => $toolData['id'],
                'tool_name' => $toolData['tool_name'],
                'input' => $toolData['input'] ?? [],
                'output' => $toolData['output'] ?? null,
                'status' => $toolData['status'],
                'duration_ms' => $toolData['duration_ms'] ?? null,
            ];

            $state['status'] = $toolData['status'] === 'running' ? 'tool_running' : 'thinking';
            $state['tools'] = $this->upsertTool($state['tools'] ?? [], $tool);

            $label = match ($toolData['status']) {
                'running' => "Calling {$toolData['tool_name']}.",
                'success' => "Completed {$toolData['tool_name']}.",
                'error' => "Failed {$toolData['tool_name']}.",
                default => "Updated {$toolData['tool_name']}.",
            };

            $state['steps'] = $this->appendStep(
                $state['steps'] ?? [],
                $this->step('tool', $label, [
                    'tool_name' => $toolData['tool_name'],
                    'status' => $toolData['status'],
                ]),
            );

            return $state;
        });
    }

    /**
     * @param  array<string, int|float|string|array<mixed>|null>  $stats
     * @return array<string, mixed>
     */
    public function finish(int $conversationId, int $messageId, array $stats): array
    {
        return $this->mutate($conversationId, function (array $state) use ($messageId, $stats): array {
            $state['status'] = 'completed';
            $state['message_id'] = $messageId;
            $state['stats'] = $stats;
            $state['steps'] = $this->appendStep($state['steps'] ?? [], $this->step('completed', 'Finished the response.'));

            return $state;
        }, 15);
    }

    /**
     * @return array<string, mixed>
     */
    public function fail(int $conversationId, string $message): array
    {
        return $this->mutate($conversationId, function (array $state) use ($message): array {
            $state['status'] = 'error';
            $state['steps'] = $this->appendStep($state['steps'] ?? [], $this->step('error', $message));

            return $state;
        }, 15);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $conversationId): array
    {
        return $this->cache->get($this->key($conversationId), [
            'conversation_id' => $conversationId,
            'status' => 'idle',
            'draft' => '',
            'steps' => [],
            'tools' => [],
            'message_id' => null,
            'stats' => null,
            'updated_at' => null,
        ]);
    }

    public function clear(int $conversationId): void
    {
        $this->cache->forget($this->key($conversationId));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function mutate(int $conversationId, callable $callback, int $minutes = 10): array
    {
        $state = $this->get($conversationId);
        $state = $callback($state);
        $state['updated_at'] = now()->toISOString();

        $this->put($conversationId, $state, $minutes);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function put(int $conversationId, array $state, int $minutes = 10): void
    {
        $this->cache->put($this->key($conversationId), $state, Carbon::now()->addMinutes($minutes));
    }

    private function key(int $conversationId): string
    {
        return "agent-run-state:{$conversationId}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $step
     * @return array<int, array<string, mixed>>
     */
    private function appendStep(array $steps, array $step): array
    {
        $steps[] = $step;

        return array_slice($steps, -20);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function hasStepType(array $steps, string $type): bool
    {
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $tool
     * @return array<int, array<string, mixed>>
     */
    private function upsertTool(array $tools, array $tool): array
    {
        foreach ($tools as $index => $existingTool) {
            if (($existingTool['id'] ?? null) === $tool['id']) {
                $tools[$index] = [...$existingTool, ...$tool];

                return array_slice($tools, -10);
            }
        }

        $tools[] = $tool;

        return array_slice($tools, -10);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function step(string $type, string $label, array $extra = []): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'at' => now()->toISOString(),
            ...$extra,
        ];
    }
}
