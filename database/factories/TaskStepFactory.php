<?php

namespace Database\Factories;

use App\Enums\TaskStepStatus;
use App\Models\Task;
use App\Models\TaskStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskStep>
 */
class TaskStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'sort_order' => 1,
            'status' => TaskStepStatus::Pending,
            'description' => fake()->sentence(),
            'prompt' => fake()->sentence(),
            'attempts' => 0,
            'max_attempts' => 3,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStepStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStepStatus::Completed,
            'result' => fake()->sentence(),
            'started_at' => fake()->dateTimeBetween('-1 hour', 'now'),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $error = 'Test step failure'): static
    {
        return $this->state(function () use ($error): array {
            return [
                'status' => TaskStepStatus::Failed,
                'error' => $error,
                'started_at' => fake()->dateTimeBetween('-1 hour', 'now'),
            ];
        });
    }
}
