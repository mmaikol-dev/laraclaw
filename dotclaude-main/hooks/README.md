# Quality Fortress — Hook Definitions

These hooks integrate into Claude Code's `settings.json` to enforce quality gates automatically.

## How Hooks Work

Claude Code supports three hook types:
- **PreToolUse** — Runs before Claude executes a tool. Can block the action.
- **PostToolUse** — Runs after Claude executes a tool. Can warn about issues.
- **Stop** — Runs when Claude is about to finish. Can force additional checks.

Each hook has a `matcher` (which tool it applies to) and a `command` (shell command to run).

## Installation

The `install.sh` script merges these hooks into your existing `settings.json` automatically. If you prefer manual installation:

1. Open `~/.claude/settings.json`
2. Find the `"hooks"` key (create it if missing)
3. Add the hooks from `quality-fortress.json` to the appropriate arrays

### Manual merge example:

```json
{
  "hooks": {
    "PreToolUse": [
      // ... your existing hooks ...
      // Add new hooks from quality-fortress.json here
    ],
    "PostToolUse": [
      // ... your existing hooks ...
      // Add new hooks from quality-fortress.json here
    ]
  }
}
```

## Hook Reference

### PreToolUse: Block --no-verify

**Matcher:** `Bash`

Prevents Claude from skipping git hooks with `--no-verify`. This flag bypasses pre-commit hooks, which defeats the purpose of having them.

### PreToolUse: Block Destructive Git

**Matcher:** `Bash`

Blocks dangerous git commands that can cause data loss:
- `git reset --hard` — Discards all uncommitted changes
- `git push --force` / `git push -f` — Overwrites remote history
- `git clean -f` — Deletes untracked files permanently
- `git checkout -- .` — Discards all working directory changes

Claude will be prompted to confirm with you before proceeding.

### PreToolUse: Block .env Writes

**Matcher:** `Write`

Prevents Claude from writing directly to `.env`, `.env.local`, or `.env.production` files. These files often contain secrets and should be managed manually. Claude should write to `.env.example` instead.

### PostToolUse: Code Quality (Edit)

**Matcher:** `Edit`

After editing any `.ts`, `.tsx`, `.js`, or `.jsx` file, checks for:
- `console.log` statements (debug leftovers)
- `any` type usage (TypeScript anti-pattern)

Reports warnings but doesn't block the edit.

### PostToolUse: Code Quality (Write)

**Matcher:** `Write`

After writing any `.ts`, `.tsx`, `.js`, or `.jsx` file, checks for:
- `console.log` statements
- `any` type usage
- Hardcoded secrets (`sk-`, `sk_live`, `PRIVATE_KEY`, `password =`)

Reports warnings, with `[CRITICAL]` for potential secrets.

## Writing Custom Hooks

```json
{
  "matcher": "ToolName",
  "hooks": [
    {
      "type": "command",
      "command": "bash -c 'input=$(cat); # your logic here'"
    }
  ]
}
```

**Tips:**
- Hook receives tool input via stdin as JSON
- Use `jq` to parse the input
- Exit code 0 = pass, exit code 2 = block (PreToolUse only)
- Write warnings to stderr (`>&2`)
- Keep hooks fast (<100ms) to avoid slowing down Claude
