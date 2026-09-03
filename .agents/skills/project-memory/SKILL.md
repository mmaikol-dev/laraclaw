---
name: project-memory
description: "Activates whenever preserving or recalling persistent project context, decisions, facts, or debugging patterns. Use when the user references stored memory, when recording architecture decisions, when saving a user preference or project fact, or after resolving a non-trivial bug that should be remembered. Also use when the project-memory or memory tool is mentioned, or when recalling prior context across sessions."
license: MIT
metadata:
  author: project
---

# Project Memory

Maintain persistent context across conversations using the application's DB-backed memory system (`AgentMemory` + the `memory` tool). The agent's system prompt already injects a memory snapshot each run; this skill defines the conventions for what to store, how to key it, and when to read/write.

## When to Use

- You are given details about the project you have not seen before.
- You need to recall a fact, decision, or preference from earlier sessions.
- You made an architecture decision worth remembering.
- You fixed a non-trivial bug and should record the pattern.
- The user stated a preference or a project fact.
- You are about to work in an unfamiliar part of the codebase.

## Tool

Use the `memory` tool with these operations:

- `set` — store a value under a key. Params: `key`, `value`, optional `category` and `scope`.
- `get` — retrieve a value by exact key.
- `search` — find memories by keyword in key or value.
- `list` — list memories, optionally filtered by `category`.
- `delete` — remove a memory by key.

### Scopes

Use the `scope` param to control visibility:

- `global` — applies everywhere (default). Use for universal debugging patterns and stable user preferences.
- `project` — this project only. Use for architecture decisions and project-specific facts.
- `user` — tied to the current user's preferences/working style.
- `environment` — environment-specific setup (Ollama host, hardware limits, etc.).
- `workflow` — how a repeatable process is done.

## Key Conventions

Store structured keys so related facts group together. Prefer dot-separated keyspaces:

- `project.overview` — one-line what this project is + tech stack.
- `project.architecture.<area>` — how a major subsystem works and why.
- `project.decision.<topic>` — an architecture/design decision and its rationale.
- `fact.<topic>` — verified facts you want to avoid re-asking.
- `preference.<topic>` — user working-style preferences.
- `debug.<symptom>` — resolved bug patterns (see Debugging Playbook section).

Keep `value` concise (a few sentences at most). The snapshot is truncated; if a stored value must be longer, store the summary in `value` and expand the detail in a file or a second key.

## Decision Recording

After agreeing on an architecture or design decision, record it:

```
set key=project.decision.<topic>
    value="Decision: <what>. Rationale: <why. Alternate considered: <what>."
    category=architecture
    scope=project
```

## Preference Recording

When the user states how they like things done:

```
set key=preference.<topic>
    value="<what they prefer and why>"
    category=user
    scope=user
```

## Debugging Playbook

After resolving a non-trivial bug, record a reusable pattern so it is checked first next time:

```
set key=debug.<short-symptom>
    value="Symptom: <what went wrong>. Root cause: <why>. Fix: <how it was fixed>. Prevention: <how to avoid>."
    category=debug
    scope=global
```

Before debugging, `search` memory for `debug.` entries matching the symptom to reuse a known fix.

## Reading Before Working

At the start of substantially unfamiliar work:

1. `get project.overview` and `list category=architecture`.
2. `list category=debug` to surface known pitfalls.
3. `list category=user` to recall working preferences.

Prefer reading memory over re-asking the user for facts already stored.
