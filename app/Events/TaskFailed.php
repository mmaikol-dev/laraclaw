<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class TaskFailed
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public string $reason,
    ) {}
}
