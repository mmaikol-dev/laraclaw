<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class TaskRetrying
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public int $attempt,
        public ?string $reason = null,
    ) {}
}
