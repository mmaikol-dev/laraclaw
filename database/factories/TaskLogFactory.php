<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\TaskLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskLog>
 */
class TaskLogFactory extends Factory
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
            'message_id' => Message::factory(),
            'tool_name' => fake()->randomElement(['file', 'shell', 'web', 'document']),
            'tool_input' => ['path' => '/tmp/laraclaw'],
            'tool_output' => fake()->sentence(),
            'status' => fake()->randomElement(['pending', 'running', 'success', 'error']),
            'error_message' => null,
            'duration_ms' => fake()->numberBetween(10, 5000),
        ];
    }
}
