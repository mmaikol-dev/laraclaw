---
name: laravel_permission_setup
description: Installs and configures spatie/laravel-permission in a Laravel project.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/laravel-permission`.
2. Publish the migration and config: `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="migrations"` and `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="config"`.
3. Run migrations: `php artisan migrate`.
4. Add the `HasRoles` trait to your User model: `use Spatie\Permission\Traits\HasRoles;`.
5. Optionally configure guard names in `config/permission.php`.
6. Use roles/permissions via `$user->assignRole('admin')`, `$user->givePermissionTo('edit posts')`, etc.
7. Ensure the skill `laravel_shadcn_project` is run first to create the base project.
