<?php

namespace Database\Factories;

use App\Models\ScheduledTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTask>
 */
class ScheduledTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'task-'.str($this->faker->unique()->word())->slug(),
            'description' => $this->faker->sentence(),
            'cron_expression' => '0 2 * * *',
            'prompt' => $this->faker->sentence(),
            'is_active' => true,
            'use_same_conversation' => false,
            'run_as_mission' => false,
        ];
    }
}
