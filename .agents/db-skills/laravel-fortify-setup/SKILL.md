---
name: laravel_fortify_setup
description: Installs and configures Laravel Fortify for authentication (2FA, password reset, etc.).
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require laravel/fortify`.
2. Publish the config and resources: `php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"`.
3. Run the Fortify installer: `php artisan fortify:install` (creates actions, views, routes).
4. In `config/fortify.php` enable features you need (e.g., `Features::twoFactorAuthentication()`).
5. Ensure `App\Providers\FortifyServiceProvider` registers the necessary callbacks (create users, update passwords, etc.).
6. Run migrations if needed: `php artisan migrate`.
7. Adjust the `User` model to implement `Laravel\Fortify\TwoFactorAuthenticatable` if using 2FA.
8. Update routes/web.php to include Fortify routes (`Route::group(['middleware' => ['web']], function () { ... });`).
9. Use the skill `laravel_shadcn_project` first to have a base project.
