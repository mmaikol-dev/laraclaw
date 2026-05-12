---
name: laravel_sanctum_setup
description: Installs and configures Laravel Sanctum for SPA/API token authentication.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require laravel/sanctum`.
2. Publish the config and migration: `php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`.
3. Run migrations: `php artisan migrate`.
4. In `app/Http/Kernel.php` add `\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class` to the `$middlewareGroups['web']` array (or to the `api` group if you prefer token‑based only).
5. In `config/sanctum.php` configure the `stateful` domains (your frontend URL) and expiration settings.
6. In your User model, add the `HasApiTokens` trait: `use Laravel\Sanctum\HasApiTokens;`.
7. Protect routes using the `auth:sanctum` middleware: `Route::middleware('auth:sanctum')->get('/user', function (Request $request) { return $request->user(); });`.
8. For SPA usage, ensure CSRF token handling (Laravel provides the `/sanctum/csrf-cookie` endpoint).
9. Run the skill `laravel_shadcn_project` first to have a base project.
