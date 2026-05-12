---
name: laravel_excel_setup
description: Installs and configures maatwebsite/excel for Excel/CSV import/export.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require maatwebsite/excel`.
2. Publish the config (optional): `php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag="config"`.
3. Create an import class: `php artisan make:import UsersImport --model=User`.
4. Implement the `model` method or `collection` method to map rows to models.
5. Use in a controller: `Excel::import(new UsersImport, $request->file('excel'));`.
6. For exports, create an export class: `php artisan make:export UsersExport --model=User` and return `Excel::download(new UsersExport, 'users.xlsx');`.
7. Adjust any needed settings in `config/excel.php` (e.g., chunk size, CSV delimiters).
8. Run the `laravel_shadcn_project` skill first to have a base Laravel project.
