<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class TaskEscalated
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public string $fromModel,
        public string $toModel,
        public string $reason,
    ) {}
}
