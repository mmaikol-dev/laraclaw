# Contributing to dotclaude

Thanks for wanting to make Claude Code better for everyone.

## What We're Looking For

### High-Impact Contributions

1. **New stack detections** — Help the autopilot recognize more project types (Ruby on Rails, Django, Flutter, etc.)
2. **New hooks** — Quality gates that catch real issues. Must be fast (<100ms) and high-signal (no noise).
3. **Memory templates** — Useful starting points for common project types (mobile, CLI tools, libraries, etc.)
4. **Install script improvements** — Better OS compatibility, edge case handling, safer merging.

### Always Welcome

- Bug fixes (especially for the install script)
- Documentation improvements
- Real-world examples of generated CLAUDE.md files

### Please Don't

- Add hooks that are slow (>100ms) or noisy (false positives)
- Add opinionated language/framework-specific rules to the base CLAUDE.md (those belong in examples)
- Add dependencies to the install script (it should work with just `bash`, `curl`, and optionally `jq`)

## How to Contribute

### Quick Fix

1. Fork the repo
2. Make your change
3. Open a PR with a clear description of what and why

### New Feature

1. Open an issue first describing what you want to add
2. Wait for a thumbs-up (saves you from building something we'd reject)
3. Fork, build, PR

### Testing Your Changes

```bash
# Test the install script on a fresh environment
docker run -it --rm ubuntu:latest bash
apt-get update && apt-get install -y curl jq git
curl -fsSL https://raw.githubusercontent.com/YOUR_FORK/dotclaude/YOUR_BRANCH/install.sh | bash

# Verify files are in place
ls -la ~/.claude/
cat ~/.claude/CLAUDE.md | head -5
ls ~/.claude/skills/project-autopilot/
```

## Code Style

- Shell scripts: use `set -euo pipefail`, quote variables, handle errors
- Markdown: keep it scannable — tables, bullets, code blocks
- JSON: valid, properly escaped, formatted with 2-space indent
- Hooks: must be a single line (shell constraint), use `bash -c '...'` wrapper

## PR Guidelines

- One feature per PR
- Clear title: `feat: add Rails stack detection` or `fix: install script on Ubuntu`
- Description explains the "why" not just the "what"
- Test on at least one OS before submitting

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
