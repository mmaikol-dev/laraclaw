<?php

use App\Jobs\CheckTriggersJob;
use App\Jobs\GenerateDailyReportJob;
use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTask;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dispatch any scheduled tasks that are now due (checked every minute)
Schedule::call(function (): void {
    ScheduledTask::where('is_active', true)->get()->each(function (ScheduledTask $task): void {
        if ($task->isDue()) {
            RunScheduledTaskJob::dispatch($task->id);
        }
    });
})->everyMinute()->name('dispatch-scheduled-tasks')->withoutOverlapping();

// Check file watcher and URL monitor triggers every 5 minutes
Schedule::job(CheckTriggersJob::class)->everyFiveMinutes()->name('check-triggers')->withoutOverlapping();

// Generate daily report at 11:55pm every day
Schedule::job(GenerateDailyReportJob::class)->dailyAt('23:55')->name('daily-report')->withoutOverlapping();
