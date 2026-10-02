<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Planning = 'planning';
    case Running = 'running';
    case Paused = 'paused';
    case Waiting = 'waiting';
    case Retrying = 'retrying';
    case Verifying = 'verifying';
    case Failed = 'failed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions($this), true);
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::Planning,
            self::Running,
            self::Paused,
            self::Waiting,
            self::Retrying,
            self::Verifying,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Cancelled,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Planning => 'Planning',
            self::Running => 'Running',
            self::Paused => 'Paused',
            self::Waiting => 'Waiting',
            self::Retrying => 'Retrying',
            self::Verifying => 'Verifying',
            self::Failed => 'Failed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Valid state transitions for the task lifecycle.
     *
     * @return array<int, self>
     */
    public static function transitions(self $status): array
    {
        return match ($status) {
            self::Pending => [self::Planning, self::Running, self::Cancelled],
            self::Planning => [self::Running, self::Failed, self::Cancelled],
            self::Running => [self::Verifying, self::Paused, self::Waiting, self::Retrying, self::Failed, self::Cancelled],
            self::Paused => [self::Running, self::Retrying, self::Cancelled],
            self::Waiting => [self::Running, self::Failed, self::Cancelled],
            self::Retrying => [self::Running, self::Failed, self::Cancelled],
            self::Verifying => [self::Completed, self::Retrying, self::Failed],
            self::Failed => [self::Retrying, self::Planning, self::Cancelled],
            self::Completed => [self::Planning],
            self::Cancelled => [],
        };
    }
}
