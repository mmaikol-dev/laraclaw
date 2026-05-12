---
name: laravel_queueable_action_setup
description: Installs and configures spatie/laravel-queueable-action to turn any class method into a queued job.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/laravel-queueable-action`.
2. Publish the config (optional): `php artisan vendor:publish --provider="Spatie\QueueableAction\QueueableActionServiceProvider" --tag="config"`.
3. Ensure a queue driver is configured in `.env` (e.g., `QUEUE_CONNECTION=database`).
4. Run the queue table migration if using the database driver: `php artisan queue:table && php artisan migrate`.
5. In any class, add the `Spatie\QueueableAction\QueueableAction` trait:
   ```php
   use Spatie\QueueableAction\QueueableAction;
   class SendWelcomeEmail {
       use QueueableAction;

       public function execute(User $user) { /* send email */ }
   }
   ```
6. Dispatch the action to the queue: `SendWelcomeEmail::dispatch($user);`.
7. Start a worker: `php artisan queue:work` (or use supervisor).
8. Run the `laravel_shadcn_project` skill first to have a base Laravel project.
