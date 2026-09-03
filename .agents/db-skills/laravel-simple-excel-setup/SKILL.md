---
name: laravel_simple_excel_setup
description: Installs and configures spatie/simple-excel for lightweight CSV/Excel handling.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/simple-excel`.
2. No publishing needed – the package works out‑of‑the‑box.
3. Reading CSV:
   ```php
   use Spatie\SimpleExcel\SimpleExcelReader;
   $rows = SimpleExcelReader::create('file.csv')
            ->useHeaders()
            ->getRows();
   foreach ($rows as $row) { /* $row is an associative array */ }
   ```
4. Writing CSV:
   ```php
   use Spatie\SimpleExcel\SimpleExcelWriter;
   $writer = SimpleExcelWriter::create('output.csv')
               ->withHeaders(['name','email']);
   $writer->addRow(['John Doe','john@example.com']);
   $writer->addRows([['Jane','jane@example.com'],['Bob','bob@example.com']]);
   ```
5. For Excel files, you can use the same API with `.xlsx` extension.
6. Run the `laravel_shadcn_project` skill first to have a base Laravel project.
