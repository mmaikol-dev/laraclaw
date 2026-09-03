<?php

namespace Database\Factories;

use App\Models\Mission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mission>
 */
class MissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'mission-'.str($this->faker->unique()->word())->slug(),
            'goal' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(['scoping', 'active', 'paused', 'completed']),
            'orchestrator_model' => null,
            'worker_model' => null,
            'validator_model' => null,
            'validation_contract' => ['The feature works end to end.', 'Tests pass with 90% coverage.'],
        ];
    }
}
