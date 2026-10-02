<?php

namespace App\Jobs;

use App\Services\TaskEngine\TaskSupervisor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SuperviseTasks implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function handle(TaskSupervisor $supervisor): void
    {
        $supervisor->inspect();
    }
}
