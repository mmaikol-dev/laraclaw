<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class TaskVerificationFailed
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public array $failedChecks,
        public string $reason,
    ) {}
}
