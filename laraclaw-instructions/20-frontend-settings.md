# 20 — Frontend: Settings Page

File: `resources/js/pages/Settings/SettingsPage.tsx`

---

## What this page does
Lets the user configure the LaraClaw agent — working directory, allowed paths, model parameters, shell/web toggles, and the system prompt. All settings are persisted to the `agent_settings` table.

---

## Data source
- Fetch current settings from `GET /api/v1/settings`
- Save changes with `POST /api/v1/settings` with body `{ settings: { key: value, ... } }`
- Show a success toast on save, error toast on failure

---

## Layout
A single scrollable page divided into named sections using Shadcn `Card` components.

---

## Section 1 — File System
| Setting key | Label | Input type | Notes |
|---|---|---|---|
| `working_dir` | Working directory | Text input | The default directory for all file operations |
| `allowed_paths` | Allowed paths | Textarea | Comma-separated list of absolute paths the agent can access |
| `max_file_size_mb` | Max file size (MB) | Number input | Largest file the agent is allowed to read |

---

## Section 2 — Shell & Web
| Setting key | Label | Input type | Notes |
|---|---|---|---|
| `enable_shell` | Enable shell tool | Toggle / Switch | Disabling prevents the agent from running any commands |
| `enable_web` | Enable web tool | Toggle / Switch | Disabling prevents web search and URL fetching |
| `shell_timeout` | Shell timeout (seconds) | Number input | Max seconds a single shell command may run |

---

## Section 3 — Model Parameters
| Setting key | Label | Input type | Notes |
|---|---|---|---|
| `temperature` | Temperature | Slider 0.0–1.0 | Show current value next to the slider |
| `context_length` | Context length (tokens) | Number input | e.g. 4096, 8192, 16384 |

---

## Section 4 — System Prompt
| Setting key | Label | Input type | Notes |
|---|---|---|---|
| `system_prompt` | System prompt | Tall textarea (~8 rows) | The instructions given to glm-5 at the start of every conversation |

---

## Save behaviour
- Track form state with `useState` initialised from the fetched settings
- Show a single "Save all settings" button at the bottom
- Disable the button while the save request is in flight
- Show a toast notification: "Settings saved" on success

---

## Dangerous zone
At the bottom of the page, a separate card titled "Danger zone" with:
- "Clear all task logs" button — calls DELETE /api/v1/tasks (add this route + controller method)
- "Clear conversation history" button — archives all conversations
- Both require a confirmation dialog before proceeding

---

## Brave API key hint
In the Web section, show a helper text link: "Get your free Brave Search API key at api.search.brave.com" if `BRAVE_API_KEY` is not configured. Since this is a backend env var, detect it by checking if the web tool returns a specific error on first use — or simply show the hint statically.
