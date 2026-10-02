<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class ToolExecutionFailed
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public string $toolName,
        public string $toolCallId,
        public string $error,
    ) {}
}
