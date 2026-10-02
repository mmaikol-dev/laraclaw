<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class ToolExecutionCompleted
{
    use Dispatchable;

    public function __construct(
        public Task $task,
        public string $toolName,
        public string $toolCallId,
        public string $output,
        public int $durationMs,
    ) {}
}
