---
name: laravel_full_project_setup
description: Creates a full Laravel project with shadcn UI and optionally installs common authentication, authorization, queue, and file handling packages. This master skill orchestrates the individual setup skills for easy reuse.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. **Parameters**
   - `project_name` (string, required): name of the Laravel project directory.
   - `install_permission` (bool, default true): whether to install spatie/laravel-permission.
   - `install_fortify` (bool, default false): install Laravel Fortify.
   - `install_sanctum` (bool, default false): install Laravel Sanctum.
   - `install_auth_log` (bool, default false): install spatie/laravel-authentication-log.
   - `install_otp` (bool, default false): install spatie/laravel-otp.
   - `install_schedule_monitor` (bool, default false): install spatie/laravel-schedule-monitor.
   - `install_queueable_action` (bool, default false): install spatie/laravel-queueable-action.
   - `install_excel` (bool, default false): install maatwebsite/excel.
   - `install_simple_excel` (bool, default false): install spatie/simple-excel.
   - `install_medialibrary` (bool, default false): install spatie/laravel-medialibrary.

2. **Orchestrate steps**
   ```
   // Step 1: Create base project with shadcn UI
   skill.run(name="laravel_shadcn_project", params={"project_name": project_name})
   
   // Change to project directory
   cd {project_name}
   
   // Step 2: Run optional setups based on flags
   if install_permission:
       skill.run(name="laravel_permission_setup")
   if install_fortify:
       skill.run(name="laravel_fortify_setup")
   if install_sanctum:
       skill.run(name="laravel_sanctum_setup")
   if install_auth_log:
       skill.run(name="laravel_authentication_log_setup")
   if install_otp:
       skill.run(name="laravel_otp_setup")
   if install_schedule_monitor:
       skill.run(name="laravel_schedule_monitor_setup")
   if install_queueable_action:
       skill.run(name="laravel_queueable_action_setup")
   if install_excel:
       skill.run(name="laravel_excel_setup")
   if install_simple_excel:
       skill.run(name="laravel_simple_excel_setup")
   if install_medialibrary:
       skill.run(name="laravel_medialibrary_setup")
   ```

3. **Post‑setup**
   - Run `composer install` if any new packages were added.
   - Run `npm install && npm run build` to compile front‑end assets.
   - Run migrations: `php artisan migrate` (covers permission, auth‑log, medialibrary, etc.).
   - Start dev server: `php artisan serve` and `npm run dev`.

4. **Usage example**
   ```bash
   skill.run(name="laravel_full_project_setup", params={
       "project_name": "my-app",
       "install_permission": true,
       "install_fortify": true,
       "install_sanctum": true,
       "install_auth_log": true,
       "install_otp": false,
       "install_schedule_monitor": true,
       "install_queueable_action": true,
       "install_excel": true,
       "install_simple_excel": false,
       "install_medialibrary": true
   })
   ```
   This will create `my-app` with shadcn UI and all selected packages ready to use.

5. **Notes**
   - Ensure PHP, Composer, Node, and npm are installed on the host before running this skill.
   - The skill assumes the base `laravel_shadcn_project` skill is already present (created earlier).
   - All sub‑skills are idempotent; re‑running the master skill will skip already‑installed packages.

6. **Linking**
   - This master skill references each individual setup skill by name, providing a single entry point for any Laravel project workflow.

Create this skill now to have a one‑stop command for full Laravel project scaffolding and optional feature installation.
