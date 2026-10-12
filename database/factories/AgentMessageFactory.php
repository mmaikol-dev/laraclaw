<?php

namespace Database\Factories;

use App\Models\AgentMessage;
use App\Models\Mission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentMessage>
 */
class AgentMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_key' => 'mission:'.fake()->numberBetween(1, 100),
            'mission_id' => null,
            'from_agent' => AgentMessage::FROM_ORCHESTRATOR,
            'to_agent' => 'general_operator',
            'kind' => 'discussion',
            'body' => fake()->sentence(),
        ];
    }

    public function forMission(Mission $mission): static
    {
        return $this->state(fn (): array => [
            'mission_id' => $mission->id,
            'thread_key' => 'mission:'.$mission->id,
        ]);
    }

    public function kind(string $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }
}
