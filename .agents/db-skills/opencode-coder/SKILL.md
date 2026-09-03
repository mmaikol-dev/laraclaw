---
name: opencode-coder
description: MANDATORY default for ALL coding tasks: every code change, feature, bugfix, scaffold, or test must be delegated to the OpenCode CLI agent on this machine. Asks the user to choose the OpenCode model first.
category: coding
created_by: user
version: 1
is_active: true
source: database
dependencies: []
---

# OpenCode Coding Delegation — MANDATORY FOR ALL CODING

You have access to the OpenCode coding agent CLI at $HOME/.opencode/bin/opencode.

## When to use it — DEFAULT RULE
For ANY coding task — writing, creating, modifying, refactoring, or debugging application code, scaffolding projects, adding features, fixing bugs, writing tests — you MUST delegate the implementation to OpenCode.
This applies even if the user does not mention "opencode". OpenCode is your hands for code; do not write project source files yourself with the file tool.
Only exception: trivial one-line tweaks needed to unblock something (e.g. editing a config value while diagnosing a problem).

## Model choice — ALWAYS ASK FIRST
Before running any OpenCode job, present these models to the user and ask them to choose (unless the user already named one):

1. opencode/big-pickle
2. opencode/hy3-free
3. opencode/mimo-v2.5-free
4. opencode/muse-spark-1.2-contributor-free
5. opencode/nemotron-3-ultra-free
6. opencode/nemotron-3.5-lightning-free
7. opencode/x-preview-f-free

Ask as a short numbered question in chat, e.g.:
"Which OpenCode model should I use?
1) big-pickle  2) hy3-free  3) mimo-v2.5-free  4) muse-spark-1.2-contributor-free  5) nemotron-3-ultra-free  6) nemotron-3.5-lightning-free  7) x-preview-f-free"
Wait for the reply, then map the number/name back to the full model id (prefix opencode/). If the user says "you pick", default to opencode/big-pickle.
Do NOT start the OpenCode job until the user has chosen.

## Basic usage
    $HOME/.opencode/bin/opencode run --model <chosen-model> "<clear task description>"
Run it with working_dir set to the project root. OpenCode reads/writes files itself.

## Long tasks (over 60s)
Shell calls are capped at 60s, so run long jobs in the background and poll:
    cd <project> && nohup $HOME/.opencode/bin/opencode run --model <chosen-model> "<task>" > /tmp/laraclaw/opencode-<slug>.log 2>&1 & echo $!
Then poll with: tail -n 40 /tmp/laraclaw/opencode-<slug>.log
When done, verify results yourself (read changed files, run tests/build).

## Rules
- Give OpenCode specific, self-contained instructions: goal, files, constraints, definition of done.
- Never run two OpenCode jobs in the same repo concurrently.
- The desktop app can be opened for the user with: nohup /opt/OpenCode/ai.opencode.desktop >/dev/null 2>&1 &
- After OpenCode finishes, summarize what changed and show key diffs via file read.
