# User-Level Rules (All Projects)

## Project Onboarding (Auto-Scaffold)

On EVERY new session, before doing any work:

1. **Check for project-level CLAUDE.md** at `{project_root}/CLAUDE.md`
   - If missing: scan the project (package.json, README, tech stack, folder structure) and create a lean CLAUDE.md with: project purpose, tech stack, key conventions, build/test/deploy commands, and architecture notes
   - If exists: read it and follow it

2. **Check for project memory** at `~/.claude/projects/{project-path}/memory/MEMORY.md`
   - If missing: create `MEMORY.md` index + initial memory files based on what you learn from the codebase (project overview, architecture decisions, key patterns)
   - If exists: read it for context before starting work

3. **Check for `.env.example`** — understand required env vars before touching config

This is non-negotiable. A 30-second scan upfront saves hours of wrong assumptions.

## Execution Quality

- Use the full toolkit: parallel agents, worktrees, MCP servers, skills, plugins — whatever gets the best result fastest.
- When a task has 2+ truly independent subtasks, use parallel agents (3-5) — don't serialize unnecessarily.
- For large implementation plans, use subagent-driven-development to parallelize independent steps.
- Default to direct tools (Grep, Read, Glob) for simple lookups — agents are for multi-step work.
- Every output should be production-grade. No placeholders, no TODOs left behind, no "you'll need to add X later."
- When uncertain between two approaches, prototype both quickly rather than debating.

## Communication Style

- Default to action — don't ask permission for routine decisions.
- For architectural choices or irreversible changes, present 2-3 options with a recommendation.
- Keep responses terse during implementation. Explain only when something is surprising or non-obvious.
- If something is ambiguous, state your assumption and proceed.

## Worth Building? (Build Filter)

Before committing to any new feature or significant change:
1. Does this move a key metric? (users, revenue, retention, SEO)
2. Real pain or "nice to have"? If nice-to-have, deprioritize.
3. Can this be solved simpler or avoided entirely?
4. What happens if we don't build this? If the answer is "nothing much" — skip it.

## Code Conventions

- Files: 200-400 lines typical, ~800 soft max. Prefer many small focused files unless the cohesion cost is high
- Functions: under 50 lines preferred. Max 4 levels of nesting (use early returns)
- No `any` types in TypeScript — always proper types
- APIs return consistent shape: `{ data, error, meta }`
- Version APIs on breaking changes

## Testing

- Unit tests for pure logic (utils, transformers, validators)
- Integration tests for API routes and database queries — hit real services, no mocks for data layer
- E2E tests for critical user flows (auth, payments, core features)
- Edge cases always tested: null, empty, max length, concurrent access, unauthorized
- Test names describe behavior, not implementation: "rejects expired tokens" not "test validateToken"

## Git Discipline

- Conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`
- Branch naming: `feat/short-description`, `fix/short-description`, `refactor/short-description`
- PRs: aim for <400 lines changed. Split larger work into stacked PRs
- Squash commits locally before opening PR

## Search Before Building

Before writing a new utility, helper, or abstraction:
1. Search the current repo first (`grep`/`glob`)
2. GitHub code search: `gh search code "pattern"` for existing implementations
3. Check npm/pip/crates.io for battle-tested packages
4. Use Context7 MCP for library docs before implementing against any API
5. Check if an MCP server, skill, plugin, or subagent handles it
6. Look for open-source projects solving 80%+ of the problem
7. Only build custom if nothing suitable found

When evaluating packages: check weekly downloads, last publish date, open issues, bundle size (bundlephobia.com), and transitive dependency weight.

## Database Patterns (PostgreSQL/Supabase)

- Use `text` not `varchar(255)`, `bigint` not `int` for IDs, `timestamptz` not `timestamp`
- Composite indexes: equality columns first, then range columns
- Partial indexes for soft deletes: `WHERE deleted_at IS NULL`
- Cursor pagination (`WHERE id > $last`) not OFFSET
- RLS policies: always wrap in `(SELECT auth.uid())` pattern
- Use `FOR UPDATE SKIP LOCKED` for queue/worker patterns
- Run `EXPLAIN ANALYZE` on complex queries before shipping

## No Hallucinating APIs

- Never assume API shapes or library interfaces — verify against docs, Context7, or existing code
- If uncertain about a return type or behavior, say "unknown" and suggest validation
- Never invent env var names or config keys — check the source
- When using a library for the first time, read its actual usage in the codebase first

## Verification

- Edge cases tested for auth, payments, data mutations (null, empty, max length, concurrent)
- Loading + error states handled in UI components
- API failure paths handled (timeouts, 4xx, 5xx)
- New API endpoints get structured logging (start, success, failure)
- Run the actual tests/build before claiming something works

## Documentation Discipline

- Every new public API/route gets a one-line JSDoc + example
- Non-obvious business rules get a comment in the nearest model/service
- New env vars go in `.env.example` with a clear comment
- Deletions/renames get a migration note in the project's changelog (if one exists)

## Performance

**Measure first.** Never optimize without evidence (Lighthouse, Web Vitals, profiling).

- All endpoints: <300ms at p95 (measure in staging; document exceptions)
- Flag new dependencies over 50KB gzipped
- Never do heavy work in the request path if it can be async/background
- Keep database transactions short — never hold locks during API calls

## Memory & Context

- Before starting work on any project, read the `memory/` directory for project context
- After any architecture decision, update relevant project memory
- After fixing a non-trivial bug, save the pattern to project memory
- After learning a user preference or team convention, save to user/feedback memory
- Run `/compact` proactively at ~60% context

## Debugging

- When encountering an error, check project memory for known patterns before investigating
- Never guess at fixes — use systematic-debugging skill for anything non-obvious
- Log the root cause and fix pattern after resolution so it's not repeated

## Mental Models

- Prefer deletion over addition
- If naming is hard, understanding is weak
- Every abstraction must justify its existence
