<?php

namespace Database\Factories;

use App\Models\Mission;
use App\Models\MissionHandoff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissionHandoff>
 */
class MissionHandoffFactory extends Factory
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
            'mission_feature_id' => null,
            'role' => $this->faker->randomElement(['orchestrator', 'worker', 'validator']),
            'summary' => $this->faker->sentence(),
            'completed_work' => $this->faker->sentence(),
            'undone_work' => null,
            'execution_log' => [['command' => 'php artisan test', 'exit_code' => 0]],
            'discovered_issues' => null,
            'procedure_adhered' => true,
        ];
    }
}
