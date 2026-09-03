# dotclaude

**Dotfiles for Claude Code.** Auto-scaffolding, quality gates, cross-project memory, and production-grade defaults — one install.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Claude Code](https://img.shields.io/badge/Claude%20Code-Compatible-blueviolet)](https://claude.ai/code)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](https://github.com/RajeshKalidandi/dotclaude/pulls)

---

## The Problem

Every time you open a project with Claude Code, you start from zero:
- No context about your codebase
- No memory of past decisions
- No quality gates catching mistakes
- No conventions enforced automatically
- You repeat the same instructions every. single. session.

## The Solution

**dotclaude** gives Claude Code a brain, muscle memory, and quality standards — out of the box.

```bash
# One command. That's it.
curl -fsSL https://raw.githubusercontent.com/RajeshKalidandi/dotclaude/main/install.sh | bash
```

## What You Get

### 1. Project Autopilot

When you open any project, Claude automatically:
- Detects your tech stack (Next.js, Python, Rust, Go, etc.)
- Generates a project-specific `CLAUDE.md` with real commands from your codebase
- Scaffolds memory files with architecture patterns and conventions
- Cross-references other projects for reusable patterns

**Before:** "Hey Claude, this is a Next.js project with Supabase and..."

**After:** Claude already knows. It read your `package.json`, mapped your routes, and loaded your database schema.

### 2. Quality Fortress (Hooks)

Automated quality gates that run on every action:

| Hook | What It Catches |
|------|----------------|
| Block `--no-verify` | Skipping git hooks |
| Block destructive git | `reset --hard`, `push --force`, `clean -f` |
| Block `.env` writes | Direct writes to env files (use `.env.example`) |
| `any` type detection | TypeScript `any` types — enforces proper typing |
| `console.log` detection | Leftover debug statements |
| Secret detection | Hardcoded API keys, passwords, private keys |

These aren't suggestions. They're **enforced automatically** via Claude Code's hook system.

### 3. Cross-Project Memory

A persistent brain that gets smarter with every session:

```
~/.claude/projects/{project}/memory/
  MEMORY.md              # Index of all memories
  project_overview.md    # What this project is, tech stack, status
  project_architecture.md # Patterns, data flow, key decisions
```

Plus **global memory** that works across all your projects:

```
~/.claude/projects/-Users-{you}/memory/
  user_profile.md           # Your role, preferences, working style
  patterns_engineering.md   # Cross-project conventions
  patterns_debugging.md     # Known bugs and fix patterns
```

Fix a Supabase RLS bug in Project A? Claude remembers the pattern when you hit the same issue in Project B.

### 4. Production-Grade CLAUDE.md

A battle-tested `CLAUDE.md` with:

- **Build Filter** — Kill bad ideas before they waste your time
- **Search Before Building** — 7-step checklist to avoid reinventing the wheel
- **Code Conventions** — File sizes, function limits, type safety, API consistency
- **Database Patterns** — PostgreSQL best practices (cursor pagination, proper types, RLS)
- **Testing Standards** — Unit/integration/E2E expectations
- **Performance Budgets** — p95 latency targets, dependency size limits
- **Parallel Execution** — Use 3-5 agents for independent tasks
- **Git Discipline** — Conventional commits, branch naming, PR size limits

## Installation

### Quick Install (Recommended)

```bash
curl -fsSL https://raw.githubusercontent.com/RajeshKalidandi/dotclaude/main/install.sh | bash
```

This will:
1. Back up your existing `~/.claude/CLAUDE.md` and `settings.json`
2. Install the dotclaude CLAUDE.md, skills, hooks, and memory templates
3. Preserve your existing plugins and marketplace config

### Manual Install

```bash
# Clone the repo
git clone https://github.com/RajeshKalidandi/dotclaude.git
cd dotclaude

# Copy files (backup your existing config first!)
cp CLAUDE.md ~/.claude/CLAUDE.md
cp -r skills/* ~/.claude/skills/
cp -r memory/templates/* ~/.claude/memory-templates/

# Merge hooks into your settings.json (don't overwrite — merge!)
# See hooks/README.md for the hook definitions to add
```

### Verify Installation

```bash
# Check CLAUDE.md is in place
cat ~/.claude/CLAUDE.md | head -5

# Check skills are installed
ls ~/.claude/skills/project-autopilot/

# Check hooks are active
cat ~/.claude/settings.json | grep -c "PreToolUse"
```

## How It Works

### Session Lifecycle

```
You open a project
        |
        v
  [Project Autopilot]
  Detect stack, scaffold CLAUDE.md & memory
        |
        v
  [Cross-Project Memory]
  Load global patterns + project-specific context
        |
        v
  [You give a task]
        |
        v
  [Quality Fortress]
  Hooks enforce standards on every action
        |
        v
  [Production-grade output]
  No placeholders. No TODOs. No `any` types.
```

### Stack Detection

The autopilot detects your stack from real files — never guesses:

| File | What It Tells Claude |
|------|---------------------|
| `package.json` | Runtime, framework, scripts, dependencies |
| `next.config.*` | Next.js version and config |
| `tsconfig.json` | TypeScript strictness |
| `prisma/schema.prisma` | Database type and ORM |
| `supabase/` | Supabase integration |
| `vercel.json` / `vercel.ts` | Hosting platform |
| `Dockerfile` | Containerized deployment |
| `pyproject.toml` | Python project |
| `Cargo.toml` | Rust project |
| `go.mod` | Go project |

## Customization

### Adapt the CLAUDE.md

The included `CLAUDE.md` is opinionated. Fork it and make it yours:

```markdown
# Your additions go here

## Team Conventions
- We use Tailwind, not CSS modules
- All API routes go in /api/v1/
- We deploy on Railway, not Vercel
```

### Add Your Own Hooks

See `hooks/README.md` for the hook format. Common additions:

```json
{
  "matcher": "Bash",
  "hooks": [{
    "type": "command",
    "command": "your-custom-check-script.sh"
  }]
}
```

### Extend Memory Templates

Add templates for your common project types in `memory/templates/`:

```markdown
---
name: project_overview
description: {Project name} overview
type: project
---

Your custom template here...
```

## Project Structure

```
dotclaude/
  CLAUDE.md                          # Production-grade global instructions
  install.sh                         # One-command installer
  skills/
    project-autopilot/
      SKILL.md                       # Auto-detect and scaffold projects
  hooks/
    README.md                        # Hook definitions and how to merge
    quality-fortress.json            # All hook configs
  memory/
    templates/
      MEMORY.md                      # Index template
      project_overview.md            # Project overview template
      project_architecture.md        # Architecture template
      user_profile.md                # User profile template
      patterns_engineering.md        # Engineering patterns template
      patterns_debugging.md          # Debugging playbook template
  examples/
    nextjs-project/                  # Example CLAUDE.md for Next.js
    python-project/                  # Example CLAUDE.md for Python
    monorepo/                        # Example CLAUDE.md for monorepos
  LICENSE
```

## FAQ

**Q: Will this overwrite my existing settings?**
A: The installer backs up everything before touching it. Manual install instructions show you how to merge.

**Q: Does this work with Claude Code plugins?**
A: Yes. dotclaude is additive — it doesn't touch your plugin config.

**Q: What if I don't use TypeScript?**
A: The hooks for `any` types and `console.log` only trigger on `.ts/.tsx/.js/.jsx` files. Everything else is language-agnostic.

**Q: Can I use this with a team?**
A: Yes. Put the `CLAUDE.md` in your repo root for team-wide conventions. Keep personal preferences in `~/.claude/CLAUDE.md`.

**Q: How is this different from just writing a good CLAUDE.md?**
A: CLAUDE.md is instructions. dotclaude is a **system** — auto-scaffolding, enforced quality hooks, persistent memory, and cross-project learning. The CLAUDE.md is just one piece.

## Contributing

PRs welcome. The best dotclaude contributions are:

1. **New stack detections** — Help the autopilot recognize more project types
2. **New hooks** — Quality gates that catch real issues (not lint noise)
3. **Memory templates** — Useful starting points for common project types
4. **Bug fixes** — Especially for the install script across different OS/shells

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## Star History

If this saves you time, star the repo. It helps others find it.

## License

MIT. Use it, fork it, make it yours.

---

**Built by [@RajeshKalidandi](https://github.com/RajeshKalidandi)** — because Claude Code deserves dotfiles too.
