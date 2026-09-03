# Example: Monorepo CLAUDE.md

This is what the Project Autopilot generates for a Turborepo monorepo.

---

```markdown
# acme-platform

Monorepo for the Acme platform — marketing site, dashboard app, and shared packages.

## Tech Stack
- Runtime: Node.js 22 (via .nvmrc)
- Monorepo: Turborepo + pnpm workspaces
- Framework: Next.js 15 (App Router) for both apps
- Database: PostgreSQL via Drizzle ORM
- Hosting: Vercel (both apps deployed separately)
- Key deps: @tanstack/react-query, tailwindcss, shadcn/ui, zod

## Commands
- Dev (all): `pnpm dev`
- Dev (web only): `pnpm dev --filter=web`
- Dev (dashboard only): `pnpm dev --filter=dashboard`
- Build: `pnpm build`
- Test: `pnpm test`
- Lint: `pnpm lint`
- DB migrate: `pnpm --filter=@acme/db migrate`

## Architecture
- `apps/web/` — Marketing site (public-facing)
- `apps/dashboard/` — Authenticated dashboard app
- `packages/ui/` — Shared component library (shadcn/ui based)
- `packages/db/` — Database schema, migrations, queries
- `packages/auth/` — Authentication helpers
- `packages/config/` — Shared ESLint, TypeScript, Tailwind configs

## Conventions
- Packages prefixed with `@acme/`
- Shared components in `packages/ui`, app-specific in `apps/*/components`
- Database queries only through `@acme/db` — no direct SQL in apps
- Environment variables scoped per app in Vercel project settings

## Key Files
- Root config: `turbo.json`, `pnpm-workspace.yaml`
- Web entry: `apps/web/src/app/layout.tsx`
- Dashboard entry: `apps/dashboard/src/app/layout.tsx`
- DB schema: `packages/db/src/schema.ts`
- Shared UI: `packages/ui/src/components/`
```
