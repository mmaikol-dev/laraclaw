<?php

namespace App\Enums;

enum TaskStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Retrying = 'retrying';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions($this), true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::Retrying], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Retrying => 'Retrying',
        };
    }

    /**
     * Valid state transitions for a task step.
     *
     * @return array<int, self>
     */
    public static function transitions(self $status): array
    {
        return match ($status) {
            self::Pending => [self::Running, self::Skipped],
            self::Running => [self::Completed, self::Failed, self::Retrying],
            self::Retrying => [self::Running, self::Failed],
            self::Completed => [],
            self::Failed => [self::Retrying],
            self::Skipped => [],
        };
    }
}
