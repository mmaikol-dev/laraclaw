<?php

namespace App\Services\Agent;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

class AffectiveStateStore
{
    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function put(int $conversationId, array $state, int $minutes = 120): array
    {
        $state['conversation_id'] = $conversationId;
        $state['updated_at'] = now()->toISOString();

        $this->cache->put(
            $this->key($conversationId),
            $state,
            Carbon::now()->addMinutes($minutes),
        );

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $conversationId): array
    {
        return $this->cache->get($this->key($conversationId), $this->defaultState($conversationId));
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function mutate(int $conversationId, callable $callback, int $minutes = 120): array
    {
        $state = $callback($this->get($conversationId));

        return $this->put($conversationId, $state, $minutes);
    }

    public function forget(int $conversationId): void
    {
        $this->cache->forget($this->key($conversationId));
    }

    private function key(int $conversationId): string
    {
        return "agent-affective-state:{$conversationId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultState(int $conversationId): array
    {
        return [
            'conversation_id' => $conversationId,
            'fear_level' => 0.0,
            'joy_score' => 0.0,
            'sadness_count' => 0,
            'anger_level' => 0,
            'curiosity_score' => 0.0,
            'love_weights' => [],
            'guilt_flags' => [],
            'boredom_counter' => 0,
            'consecutive_failures' => 0,
            'repetition_counter' => 0,
            'last_tool_name' => null,
            'last_block_reason' => null,
            'user_goal' => null,
            'updated_at' => null,
        ];
    }
}
