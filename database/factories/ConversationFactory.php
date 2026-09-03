<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'model' => 'glm-5:cloud',
            'system_prompt' => ['tone' => 'helpful'],
            'total_tokens' => fake()->numberBetween(0, 4000),
            'is_archived' => false,
        ];
    }
}
