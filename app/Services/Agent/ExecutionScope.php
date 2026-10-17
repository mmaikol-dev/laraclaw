<?php

namespace App\Services\Agent;

use App\Models\Mission;
use App\Models\Task;

/**
 * Tracks what the agent is currently executing on behalf of (a task or a
 * mission) for the duration of a single run.
 *
 * Tools that require execution-scoped authorization — such as elevated (sudo)
 * shell access — read this rather than trusting the model's own claim. The
 * scope is set by the task engine / mission runner around each agent run and
 * cleared afterwards, so a grant never leaks between runs in a long-lived
 * worker.
 */
class ExecutionScope
{
    protected ?Task $task = null;

    protected ?Mission $mission = null;

    public function beginTask(Task $task): void
    {
        $this->task = $task;
        $this->mission = null;
    }

    public function beginMission(Mission $mission): void
    {
        $this->mission = $mission;
        $this->task = null;
    }

    public function end(): void
    {
        $this->task = null;
        $this->mission = null;
    }

    public function task(): ?Task
    {
        return $this->task;
    }

    public function mission(): ?Mission
    {
        return $this->mission;
    }

    /**
     * Whether elevated (sudo) privileges are approved for the current run.
     *
     * A task approved directly grants sudo, and a mission approved for its
     * lifetime grants sudo to every task/work unit it runs.
     */
    public function sudoAllowed(): bool
    {
        if ($this->task !== null && $this->task->sudoAllowed()) {
            return true;
        }

        return $this->mission !== null && $this->mission->sudoAllowed();
    }
}
