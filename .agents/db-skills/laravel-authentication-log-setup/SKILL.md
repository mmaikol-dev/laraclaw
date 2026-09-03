---
name: laravel_authentication_log_setup
description: Installs and configures spatie/laravel-authentication-log to log successful and failed login attempts.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/laravel-authentication-log`.
2. Publish the migration and config: `php artisan vendor:publish --provider="Spatie\AuthenticationLog\AuthenticationLogServiceProvider" --tag="migrations"` and `php artisan vendor:publish --provider="Spatie\AuthenticationLog\AuthenticationLogServiceProvider" --tag="config"`.
3. Run migrations: `php artisan migrate`.
4. In `app/Providers/EventServiceProvider.php` register the listeners:
   ```php
   protected $listen = [
       \Illuminate\Auth\Events\Login::class => [\Spatie\AuthenticationLog\Listeners\LogSuccessfulLogin::class],
       \Illuminate\Auth\Events\Failed::class => [\Spatie\AuthenticationLog\Listeners\LogFailedLogin::class],
   ];
   ```
5. Optionally customize the `config/authentication-log.php` file (e.g., log channel, IP address handling).
6. Run `php artisan config:cache` to refresh config.
7. Ensure the skill `laravel_shadcn_project` is run first to have a base Laravel project.
