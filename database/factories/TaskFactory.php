<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => TaskStatus::Pending,
            'goal' => fake()->sentence(),
            'current_step' => 0,
            'total_steps' => 0,
            'attempts' => 0,
            'max_attempts' => 5,
            'heartbeat_interval_seconds' => 90,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => TaskStatus::Pending]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Running,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => TaskStatus::Completed,
            'started_at' => fake()->dateTimeBetween('-1 hour', 'now'),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Test failure'): static
    {
        return $this->state(function () use ($reason): array {
            return [
                'status' => TaskStatus::Failed,
                'failure_reason' => $reason,
                'started_at' => fake()->dateTimeBetween('-1 hour', 'now'),
                'completed_at' => now(),
            ];
        });
    }

    public function stale(): static
    {
        return $this->running()->state(fn (): array => [
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);
    }
}
