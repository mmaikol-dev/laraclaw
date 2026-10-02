<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

class TaskStarted
{
    use Dispatchable;

    public function __construct(public Task $task) {}
}
