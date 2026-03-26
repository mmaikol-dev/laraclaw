<?php

namespace Database\Factories;

use App\Models\AgentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentSetting>
 */
class AgentSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, '_'),
            'value' => fake()->word(),
            'type' => 'string',
            'label' => fake()->sentence(2),
            'description' => fake()->sentence(),
        ];
    }
}
