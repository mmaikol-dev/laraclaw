---
name: quality-enforcement
description: "Activates whenever writing or editing code that could introduce quality or security regressions. Use when finalizing any code change, before committing, or when checking for secrets, debug statements, type loosening, or git safety violations. Activates on 'lint', 'quality', 'secrets', 'no-verify', 'force push', 'console.log'."
license: MIT
metadata:
  author: project
---

# Quality Enforcement

Gate every change so code stays secure, clean, and reviewable. Follow these checks before finalizing any work.

## Never Commit Secrets

- Never commit real API keys, passwords, or tokens (`.env` values, `TAVILY_API_KEY`, DB passwords, etc.).
- Never use the `env()` function outside `config/` files; use `config()`.
- When adding a new environment variable, add a placeholder to `.env.example`.
- Before finalizing, scan new/changed files for hardcoded secrets: `sk-`, `sk_live`, `password =`, `PRIVATE_KEY`, bearer tokens.
- If a secret was accidentally committed, rotate it and scrub history before pushing.

## No Debug Leftovers

- Remove `console.log` / `dump()` / `dd()` / `var_dump()` / `print_r()` debug statements from any change.
- Remove commented-out debug code rather than leaving it.
- Do not commit temporary `tinker`/verification scripts (see AGENTS.md: prefer real tests).

## Type Safety

- PHP: always use explicit return types and parameter type hints.
- TypeScript: do not introduce `any`. If a type is unknown, resolve it properly instead of loosening.
- Keep the API shape consistent (`{ data, error, meta }` style used in `@/lib/api.ts`).

## Run the Checks

Verify your change against the project tooling before finalizing:

- PHP: `vendor/bin/pint --dirty --format agent`
- Frontend: `npm run lint:check` and `npm run types:check`
- Tests: run the minimum tests covering your change (see PHPUnit rules)

## Git Hygiene

- Use Conventional Commits: `feat:`, `fix:`, `chore:`, `refactor:`, `docs:`, `test:`, `perf:`.
- Keep commits to one concern; keep PRs small (under ~400 changed lines); squash WIP before PR.
- Never `git push --force`/`-f` to shared branches, never `git reset --hard` on shared history.
- Never skip git hooks with `--no-verify`. If a hook rejects a commit, fix the underlying issue rather than bypassing it.
- Stage only intended files; never commit unrelated changes or secrets.

## Before You Say "Done"

Ask yourself: Is the code formatted, linted, type-checked, tested, secret-free, and committed cleanly? If any answer is no, fix it before claiming completion.
