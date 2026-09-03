<?php

namespace App\Jobs;

use App\Services\Agent\ProactiveMonitoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshProactiveFindingsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function handle(ProactiveMonitoringService $monitoring): void
    {
        $monitoring->refreshFindings();
    }
}
