<?php

namespace Database\Factories;

use App\Models\MetricSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tokens_per_second' => fake()->randomFloat(2, 0, 200),
            'prompt_tokens' => fake()->numberBetween(0, 4000),
            'completion_tokens' => fake()->numberBetween(0, 4000),
            'total_duration_ms' => fake()->numberBetween(10, 10000),
            'load_duration_ms' => fake()->numberBetween(0, 3000),
            'model' => 'glm-5:cloud',
            'tool_name' => null,
            'extra' => ['source' => 'factory'],
            'recorded_at' => now(),
        ];
    }
}
