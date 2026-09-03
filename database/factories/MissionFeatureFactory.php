<?php

namespace Database\Factories;

use App\Models\Mission;
use App\Models\MissionFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissionFeature>
 */
class MissionFeatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mission_id' => Mission::factory(),
            'title' => $this->faker->unique()->sentence(3),
            'description' => $this->faker->paragraph(),
            'milestone' => 'M1',
            'status' => $this->faker->randomElement(['pending', 'in_progress', 'implemented', 'validated', 'blocked']),
            'sort_order' => 0,
            'attempts' => 0,
        ];
    }
}
