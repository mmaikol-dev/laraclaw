---
name: laravel_shadcn_project
description: Creates a new Laravel project with the React starter kit and installs shadcn/ui components, ready for development.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Ensure PHP, Composer, Node, npm are installed. If not, install them.
2. Run `laravel new <project-name>` (or `composer create-project laravel/laravel <project-name>`) and select the React starter kit.
3. cd into the project directory.
4. Run `npm install` to install front‑end deps.
5. Install shadcn UI: `npm i -D shadcn-ui && npx shadcn@latest init`.
6. Use `npx shadcn@latest add <component>` to publish needed components.
7. Optionally switch layouts by editing `resources/js/layouts/app-layout.tsx`.
8. Start dev servers: `php artisan serve` and `npm run dev`.
9. Return the path of the created project and a brief success message.
