<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => fake()->randomElement(['user', 'assistant', 'tool']),
            'content' => fake()->sentence(),
            'tool_calls' => null,
            'tool_result' => null,
            'tool_name' => null,
            'prompt_tokens' => fake()->numberBetween(0, 500),
            'completion_tokens' => fake()->numberBetween(0, 500),
            'tokens_per_second' => fake()->randomFloat(2, 0, 100),
            'duration_ms' => fake()->numberBetween(50, 5000),
        ];
    }
}
