---
name: laravel_schedule_monitor_setup
description: Installs and configures spatie/laravel-schedule-monitor to monitor scheduled tasks via UI.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/laravel-schedule-monitor`.
2. Publish the config and migration:
   ```bash
   php artisan vendor:publish --provider="Spatie\ScheduleMonitor\ScheduleMonitorServiceProvider" --tag="config"
   php artisan vendor:publish --provider="Spatie\ScheduleMonitor\ScheduleMonitorServiceProvider" --tag="migrations"
   ```
3. Run migrations:
   ```bash
   php artisan migrate
   ```
4. In `app/Console/Kernel.php` register the monitor:
   ```php
   protected function schedule(Schedule $schedule)
   {
       $schedule->command('your:command')
                ->daily()
                ->monitor(); // adds monitoring
   }
   ```
5. Optionally customize the UI routes in `config/schedule-monitor.php` (e.g., prefix, middleware).
6. Access the monitoring UI at `/schedule-monitor` (or the configured path).
7. Run the skill `laravel_shadcn_project` first to have a base Laravel project.
