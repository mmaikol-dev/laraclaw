---
name: debugging-playbook
description: "Activates when debugging a bug or unexpected behavior. Use when reproducing a failure, investigating logs or errors, when the user reports something broken, or after resolving any non-trivial bug to record the pattern. Activates on keywords like 'bug', 'error', 'failing', 'not working', 'broken', 'exception', 'debug'."
license: MIT
metadata:
  author: project
---

# Debugging Playbook

A disciplined, repeatable process for finding root causes and for banking the fix so the same bug is never debugged twice.

## Reproduce First

- Never guess. Reproduce the failure, then inspect actual state (logs, `database-query`, `tinker`, `browser-logs`).
- Confirm the root cause with evidence before changing anything.

## Check Memory for Known Patterns

Before deep investigation, search stored memory for a previously resolved bug with the same symptom:

```
memory search query=debug.<symptom>
memory list category=debug
```

If a matching pattern exists, apply the known fix first.

## Investigate

- Read recent browser/console logs (`browser-logs`) and `storage/logs/laravel.log`.
- Inspect the relevant DB rows with `database-query` or `php artisan tinker --execute`.
- Confirm the assumptions about the failing code path before editing.
- Prefer targeted verification (one test, one tinker query) over broad guess-and-check.

## Fix

- Apply the smallest change that addresses the root cause (not the symptom).
- Add or update a test that reproduces the bug and passes only with the fix.
- Run the relevant test before finalizing.

## Record the Pattern

After resolving a non-trivial bug, save it so it is checked first next time. Store the entry in memory so the snapshots and future searches surface it:

```
memory set
  key="debug.<short-symptom>"
  value="Symptom: <what went wrong>. Root cause: <why>. Fix: <how it was fixed>. Prevention: <how to avoid recurrence>."
  category=debug
  scope=global
```

Keep the `value` tight—one or two sentences per field.

## Playbook Entry Format

Every recorded bug follows this shape (used in memory and any `DEBUGGING.md`):

| Field | What it holds |
|-------|---------------|
| Symptom | What went wrong, as observed. |
| Root cause | The underlying reason. |
| Fix | The change that resolved it. |
| Prevention | How to avoid it happening again. |
