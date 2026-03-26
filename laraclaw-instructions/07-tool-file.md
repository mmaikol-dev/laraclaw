# 07 — FileTool

## What to do
Create `app/Services/Tools/FileTool.php` extending `BaseTool`.

This tool gives the agent access to the Linux file system within allowed paths.

---

## Tool name
`file`

## Actions to implement
The tool takes an `action` parameter. Implement all of these:

| Action | What it does |
|---|---|
| `read` | Read and return file contents with a header showing path and line count |
| `write` | Overwrite a file with new content (creates parent dirs if needed) |
| `create` | Create a new file — error if it already exists |
| `delete` | Delete a file or directory (recursive for directories) |
| `list` | List a directory — flat or recursive depending on a `recursive` boolean param |
| `search` | Find files matching a glob/substring pattern inside a directory |
| `move` | Move/rename a file or directory |
| `copy` | Copy a file to a destination |

## Parameters schema
Required: `action`
Optional: `path`, `content` (for write/create), `destination` (for move/copy), `pattern` (for search), `recursive` (boolean)

---

## Path resolution
- If `path` does not start with `/`, prepend the `working_dir` from `AgentSetting`
- Always resolve the final path with `realpath()` before the guard check

---

## Security guard (CRITICAL)
Every action must call a private `guard(string $path)` method before touching the filesystem.

The guard must:
1. Hardblock these system directories regardless of settings — `/etc`, `/sys`, `/proc`, `/boot`, `/root`, `/dev`, `/run`, `/snap`
2. Check that the resolved path starts with one of the paths from `AgentSetting::get('allowed_paths')` plus the `working_dir` and `/tmp/laraclaw`
3. Throw a `RuntimeException` with a clear message if access is denied

Refresh allowed paths from `AgentSetting` on every `execute()` call so settings changes take effect immediately.

---

## File size check
Before reading a file, check its size against `AgentSetting::get('max_file_size_mb')`. Throw an exception if it exceeds the limit.

---

## Output format
- `read` — prepend a header: `=== /path/to/file (N lines) ===` then the file content
- `list` — each entry on its own line, prefixed with `[dir]` or `[file]`, include human-readable size for files
- Always call `$this->truncate()` on output before returning

---

## isEnabled
Always returns `true` (file access is always on).
