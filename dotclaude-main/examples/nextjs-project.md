# Example: Next.js Project CLAUDE.md

This is what the Project Autopilot generates for a typical Next.js + Supabase project.

---

```markdown
# my-saas-app

SaaS application for team collaboration with real-time features.

## Tech Stack
- Runtime: Node.js 22 (via .nvmrc)
- Framework: Next.js 15 (App Router)
- Database: PostgreSQL via Supabase (with RLS)
- ORM: Prisma 6.x
- Hosting: Vercel
- Key deps: @supabase/ssr, zustand, zod, tailwindcss, shadcn/ui

## Commands
- Dev: `pnpm dev`
- Build: `pnpm build`
- Test: `pnpm test`
- Lint: `pnpm lint`
- Deploy: `git push` (Vercel auto-deploys)
- DB migrate: `pnpm prisma migrate dev`
- DB studio: `pnpm prisma studio`

## Architecture
- `src/app/` — File-based routing (App Router)
- `src/app/api/` — API routes (REST)
- `src/lib/` — Shared utilities, Supabase client, auth helpers
- `src/components/` — React components (feature-grouped)
- `src/hooks/` — Custom React hooks
- `prisma/` — Database schema and migrations

## Conventions
- Named exports for components, default exports for pages
- Absolute imports via `@/` prefix
- Zustand for client state, server components for server state
- Zod schemas colocated with API routes
- Error boundaries at layout level

## Key Files
- Entry: `src/app/layout.tsx`
- Config: `next.config.ts`, `tailwind.config.ts`
- Routes: `src/app/api/`
- Schema: `prisma/schema.prisma`
- Environment: `.env.example`
```
