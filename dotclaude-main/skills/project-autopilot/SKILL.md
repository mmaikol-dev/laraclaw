---
name: project-autopilot
description: Auto-detect project type, scaffold CLAUDE.md and memory on first session. Use at the start of every session in a project directory.
---

# Project Autopilot

Intelligent project onboarding that runs on session start. Detects the project, scaffolds context files, and gets you productive in seconds.

## When to Use

- **Every session start** in a project directory
- When the project is missing CLAUDE.md or project memory
- When switching to a project you haven't touched recently
- After cloning a new repo

## Phase 1: Detect Project State

Check these in parallel:

```
1. Does {project_root}/CLAUDE.md exist?
2. Does ~/.claude/projects/{project-path}/memory/MEMORY.md exist?
3. What's the tech stack? (scan for package.json, requirements.txt, Cargo.toml, go.mod, Gemfile, pyproject.toml, composer.json, Makefile, Dockerfile)
4. Is this a monorepo? (check for workspaces in package.json, turborepo.json, pnpm-workspace.yaml, nx.json)
5. What framework? (next.config.*, nuxt.config.*, svelte.config.*, astro.config.*, vite.config.*)
6. What database? (prisma/, drizzle.config.*, supabase/, .env* for DATABASE_URL)
7. What CI/CD? (.github/workflows/, vercel.json, vercel.ts, netlify.toml, Dockerfile)
8. Git state? (git log --oneline -5, current branch, remote)
```

## Phase 2: Scaffold CLAUDE.md (if missing)

Generate a project-level CLAUDE.md by READING the actual codebase. Never guess.

Structure:

```markdown
# {Project Name}

{One-line description derived from README or package.json}

## Tech Stack
- Runtime: {node/python/rust/go + version from .tool-versions, .nvmrc, package.json engines}
- Framework: {next/nuxt/svelte/express/fastapi/etc + version}
- Database: {postgres/supabase/sqlite/mongo + ORM}
- Hosting: {vercel/aws/railway/fly + evidence}
- Key deps: {list top 5-8 non-obvious dependencies}

## Commands
- Dev: `{actual dev command from package.json scripts or Makefile}`
- Build: `{actual build command}`
- Test: `{actual test command}`
- Lint: `{actual lint command}`
- Deploy: `{actual deploy command if discoverable}`
- DB migrate: `{actual migration command if applicable}`

## Architecture
- {Map the top-level directory structure}
- {Identify the routing pattern: file-based, explicit, etc}
- {Identify the data flow: API routes -> services -> DB, or direct, etc}
- {Note any patterns: repository pattern, service layer, etc}

## Conventions (derived from existing code)
- {Naming patterns observed in the codebase}
- {Import style: absolute vs relative, barrel exports}
- {State management approach if frontend}
- {Error handling patterns}
- {Testing patterns if tests exist}

## Key Files
- Entry point: `{path}`
- Config: `{path}`
- Routes/API: `{path}`
- Database schema: `{path}`
- Environment: `{.env.example or .env.local path}`
```

IMPORTANT:
- Every field must come from READING actual files. Never fabricate.
- If a field can't be determined, omit it. Don't write "unknown."
- Run `cat package.json | jq '.scripts'` to get real commands.
- Run `ls -la` at key directories to map structure.
- Read 2-3 source files to detect conventions (naming, imports, patterns).

## Phase 3: Scaffold Memory (if missing)

Create project memory at `~/.claude/projects/{project-path}/memory/`:

### MEMORY.md (index)
```markdown
- [Project Overview](project_overview.md) -- tech stack, purpose, architecture summary
- [Architecture](project_architecture.md) -- key patterns, data flow, component structure
```

### project_overview.md
```markdown
---
name: project_overview
description: {Project name} -- {one-line summary of what it does and key tech}
type: project
---

{2-3 sentences: what it is, who it's for, current state}

**Tech stack:** {framework + db + hosting}
**Repo:** {remote URL if available}
**Status:** {active/maintenance/new based on recent commit activity}
```

### project_architecture.md
```markdown
---
name: project_architecture
description: Architecture patterns and key decisions for {project name}
type: project
---

{Key architectural patterns observed}

**Why:** {Inferred from code structure}
**How to apply:** {When making changes, follow these patterns}
```

## Phase 4: Cross-Pollinate

After scaffolding, check global memory at `~/.claude/projects/-Users-{username}/memory/` for:
- Similar tech stacks in other projects — reuse known patterns
- Debugging patterns that apply across projects
- User preferences that should carry over

## Rules

1. **Never block the user.** If they give a task immediately, do the scan in background or weave it into the first task.
2. **Never fabricate.** Every claim in CLAUDE.md must trace to an actual file.
3. **Keep it lean.** CLAUDE.md should be <100 lines. Memory files should be <50 lines each.
4. **Update, don't duplicate.** If files exist but are stale, update them. Don't create new ones.
5. **Parallel everything.** Use parallel tool calls to scan package.json, README, directory structure, and recent git history simultaneously.

## Quick Reference: Stack Detection

| File | Signals |
|------|---------|
| `package.json` | Node.js, framework (next/nuxt/svelte), scripts, deps |
| `next.config.*` | Next.js + version |
| `tsconfig.json` | TypeScript + strictness level |
| `prisma/schema.prisma` | Prisma ORM + database type |
| `drizzle.config.*` | Drizzle ORM |
| `supabase/` or `.env*SUPABASE*` | Supabase |
| `vercel.json` or `vercel.ts` | Vercel hosting |
| `.github/workflows/` | GitHub Actions CI |
| `Dockerfile` | Containerized deployment |
| `tailwind.config.*` | Tailwind CSS |
| `requirements.txt` / `pyproject.toml` | Python project |
| `Cargo.toml` | Rust project |
| `go.mod` | Go project |
| `turbo.json` / `pnpm-workspace.yaml` | Monorepo |
