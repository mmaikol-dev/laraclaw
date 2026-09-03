# 08 — ShellTool

## What to do
Create `app/Services/Tools/ShellTool.php` extending `BaseTool`.

Executes sandboxed Linux shell commands on behalf of the agent.

---

## Tool name
`shell`

## Parameters schema
Required: `command`
Optional: `working_dir` (string), `timeout` (integer, max 60, default 30)

---

## isEnabled
Return `(bool) AgentSetting::get('enable_shell', true)` — respects the toggle in Settings.

---

## Blocked commands (hardcoded list)
Before executing any command, scan it (lowercase) for these substrings and throw a `RuntimeException` if any match:

- `sudo`, `su `, `passwd`, `useradd`, `userdel`, `usermod`
- `visudo`, `chmod 777`, `rm -rf /`, `mkfs`, `fdisk`
- `dd if=`, `shutdown`, `reboot`, `halt`, `poweroff`, `init 0`
- `iptables`, `ufw`, `systemctl enable`, `systemctl disable`
- `crontab`, `nohup`, `| bash`, `| sh`, `bash <(`, `curl | bash`, `wget | bash`

Error message should clearly state which pattern was blocked.

---

## Command execution
- Use `proc_open` with descriptors for stdin, stdout, stderr
- Wrap the command with `timeout {N} bash -c '...'` using `escapeshellarg`
- Set the working directory to the `working_dir` argument (fallback to `AgentSetting::get('working_dir')`)
- Capture both stdout and stderr
- Read exit code from `proc_close`
- Exit code 124 means the timeout was hit — append a note to the output
- Any non-zero exit code — append `[Exit code: N]` to output
- Call `$this->truncate($output, AgentSetting::get('max_output_lines', 500))` before returning

---

## Output format
Return the combined stdout + stderr. If there is no output at all, return `"(no output)"`.
Prepend stderr with `[stderr]` on its own line so the agent can distinguish it.
