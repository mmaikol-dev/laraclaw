# LaraClaw

**A local-first autonomous AI agent platform for Linux, macOS, Windows (WSL/Git Bash), and Android (Termux).**

LaraClaw is a Laravel 13 + Inertia v2/React 19 application that turns local or cloud
LLMs (via [Ollama](https://ollama.com)) into a persistent, tool-using agent. It ships a
streaming chat agent, a durable task-execution engine, and an autonomous "AI employee"
that works through scheduled tasks, triggers, projects, and social-media operations —
all on your own machine.

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Persistent credentials (important)](#persistent-credentials-important)
  - [Managing credentials in the UI](#managing-credentials-in-the-ui)
- [Running the application](#running-the-application)
- [How it works](#how-it-works)
- [System architecture](#system-architecture)
- [Request & data flow](#request--data-flow)
- [Task lifecycle](#task-lifecycle)
- [Project structure](#project-structure)
- [Tools](#tools)
- [HTTP API](#http-api)
- [Testing, linting & type checking](#testing-linting--type-checking)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

- **Streaming agent chat** — token-by-token responses with live tool-call visibility,
  thinking-trace rendering, cancellation, and multi-step tool loops.
- **Durable Task Engine** — plan → execute → verify → repair. Tasks survive worker
  restarts via checkpoints, retry transient failures, escalate to stronger models, and
  an independent verifier gates completion (the model never marks its own work done).
- **Agentic Employee** — scheduled tasks, file/URL triggers, project continuation,
  persistent memory, and daily reports that run automatically.
- **Missions & multi-agent office** — role-based agents (planner, coder, tester,
  reviewer) coordinated by an orchestrator, with optional Hermes3D visual presence.
- **Rich tool set** — file, shell, web, browser, memory, OpenCode, document/RAG,
  Docker, Cloudflare, WhatsApp, YouTube, Instagram, Facebook, Google Sheets, and
  media clipping.
- **Skill system** — reusable, versioned agent skills with scripts, synced from the
  project workspace.
- **Local-first** — SQLite/MySQL, Ollama models, and a PWA-ready UI that can be
  installed as a desktop or phone app.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13, PHP 8.3 |
| Frontend | React 19 + TypeScript, Inertia v2, Tailwind CSS v4, shadcn/ui + Radix |
| Auth | Laravel Fortify, Sanctum |
| Routing | Laravel Wayfinder (typed TS route functions) |
| AI runtime | Ollama (`/api/chat` streaming + embeddings) |
| Database | MySQL (default) or SQLite (Android / tests) |
| Queues | Laravel queue (`database`) + scheduler |
| Testing | PHPUnit 12 |
| Build | Vite 8 |

---

## Prerequisites

- PHP **8.3+** with `pdo`, `mbstring`, `curl`, `xml`, `zip`, `gd`, `bcmath`, `intl`
- Composer 2
- Node.js **20+** and npm
- [Ollama](https://ollama.com) running locally (default `http://127.0.0.1:11434`)
- MySQL 8 (or SQLite) — optional for non-Android installs that prefer MySQL
- Google Chrome / Chromium (only for the browser-automation tool)

---

## Installation

### Quick install (recommended)

The installer detects your OS and sets up PHP, Node, the database, Ollama, models,
autostart services, and the app itself:

```bash
curl -fsSL https://raw.githubusercontent.com/mmaikol-dev/laraclaw/main/install.sh | bash
```

It supports **Linux**, **WSL**, **macOS**, **Windows (Git Bash/WSL)**, and
**Android (Termux)**. On Android it uses SQLite, Supervisor for process management,
and installs the app as a PWA.

### Manual install

```bash
# 1. Clone
git clone https://github.com/mmaikol-dev/laraclaw.git
cd laraclaw

# 2. PHP + JS dependencies
composer install
npm install

# 3. Environment file
cp .env.example .env
php artisan key:generate

# 4. Database
#    MySQL: create the `laraclaw` database and set DB_* in .env
#    SQLite: set DB_CONNECTION=sqlite and run `touch database/database.sqlite`
php artisan migrate

# 5. AI models (adjust to your preference)
ollama pull qwen3:4b
ollama pull qwen3-embedding:0.6b

# 6. Create the persistent credential + working directories (see Configuration)
mkdir -p "$HOME/.laraclaw/working" "$HOME/laraclaw-media"
```

---

## Configuration

### Persistent credentials (important)

Credentials (social tokens, API keys) are **not** stored in the database. They live in a
plain-text env file on disk so they survive reboots, `/tmp` cleanups, and app restarts.

| What | Default | Env variable |
|------|---------|--------------|
| Credentials file | `~/.laraclaw/.env` | `AGENT_CREDENTIALS_FILE` |
| Agent working dir | `~/.laraclaw/working` | `AGENT_WORKING_DIR` |
| Media library root | `~/laraclaw-media` | `AGENT_MEDIA_ROOT` |
| Allowed agent paths | `~/.laraclaw`, working dir, `~/projects`, `~` | `AGENT_ALLOWED_PATHS` |

> **Do not** point `AGENT_CREDENTIALS_FILE` at `/tmp`. That location is wiped by the OS
> and by tmp cleanup services, which is how a credentials file can silently disappear.

The file is a simple `KEY=value` list. Lines starting with `#` are ignored. Example:

```bash
mkdir -p "$HOME/.laraclaw/working"
cat > "$HOME/.laraclaw/.env" <<'ENV'
# LaraClaw persistent credentials. Never commit this file.
INSTAGRAM_ACCESS_TOKEN=
FACEBOOK_ACCESS_TOKEN=
YOUTUBE_REFRESH_TOKEN=
OPENWA_API_KEY=
OPENWA_SESSION_ID=
ENV
chmod 600 "$HOME/.laraclaw/.env"
```

The Instagram, Facebook, YouTube, WhatsApp, and other tools read tokens from this file
whenever a token is not passed directly to the tool call.

### Managing credentials in the UI

You can view and edit the credentials file from the app:

1. Sign in and open **Settings** (gear icon) → **Credentials** tab,
   or go directly to `/settings/credentials`.
2. Edit the text area and click **Save file**.

The page always targets the configured `AGENT_CREDENTIALS_FILE` path — it cannot read or
write other locations. The file is created automatically on first save and is written
with `0600` permissions. The page shows the **full path** and whether the file already
exists.

Other Settings tabs: **Profile**, **Security**, **Appearance**, **Agent**.

---

## Running the application

### Development (with hot reload)

The recommended way — this runs the PHP server, queue worker, log viewer, Vite dev
server, scheduler, and the optional Hermes3D studio together:

```bash
composer run dev
```

To run the pieces individually:

```bash
php artisan serve            # web server (default http://127.0.0.1:8000)
php artisan queue:work       # background jobs (task engine, missions)
php artisan schedule:work    # scheduler (triggers, supervisor, daily reports)
npm run dev                  # Vite dev server (HMR)
```

> On Android/Termux use `composer run android` (SQLite + Supervisor) and install the
> PWA from the browser when prompted.

### Production

```bash
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run `php artisan queue:work` under a process manager (systemd, Supervisor) and add
`php artisan schedule:run` to cron every minute.

---

## How it works

LaraClaw has two coupled subsystems that share one tool registry and one Ollama client.

1. **Conversational agent (`AgentService`)** — drives streaming chat. Each turn runs a
   tool loop: the model streams text, may emit tool calls, the registry executes them,
   results are fed back, and the loop continues until the model produces a final answer
   or hits the iteration cap. Run state (draft text, tool steps, stats, cancel flag) is
   held in the cache so the UI can poll/stream it without hammering the database.

2. **Durable Task Engine (`TaskEngine`)** — the source of truth for long-running work.
   A goal is planned into steps (`TaskStep`), executed one step per `ExecuteTaskStepJob`,
   checkpointed (`TaskCheckpoint`), and only marked complete after an **independent
   verification** pass (`VerificationService`). Transient failures are classified
   (`FailureClassifier`) and retried; repeated patterns are caught by `LoopDetector`;
   stalled tasks are recovered by `TaskSupervisor`.

3. **Agentic employee layer** — `MissionService`, `AgentOrchestrator`,
   `RoleProfileService`, `ProactiveMonitoringService`, and scheduled jobs give the agent
   autonomy: it wakes on a cron/trigger, picks up work, executes it via the task engine,
   records outcomes (`OutcomeRecorder`), and reports.

**Tool registry** — every tool (`app/Services/Tools/`) is registered in
`AgentServiceProvider` and gated by an `AGENT_ENABLE_*` flag. Tools receive structured
parameters, return text, and are logged to the `events` table.

**Model routing** — `ModelRouter` picks a model per task complexity
(simple / coding / reasoning / review / complex), defaulting to `OLLAMA_AGENT_MODEL`.

---

## System architecture

```
                          +--------------------------------------+
                          |        Browser (React + Inertia)      |
                          |  chat · tasks · missions · media ...   |
                          +------------------+-------------------+
                                             | HTTP + streaming (SSE)
                                             v
                          +--------------------------------------+
                          |            Laravel 13 (PHP)           |
                          |  Controllers -> Inertia pages         |
                          |  /api/v1 JSON API + webhooks          |
                          +----+---------------------------+------+
                               |                           |
              dispatch job     |                           | run inline
                               v                           v
                    +--------------------+      +------------------------+
                    |   Queue (database)  |      |      AgentService      |
                    |  ExecuteTaskStepJob |      |  (streaming tool loop) |
                    |  FinalizeTaskJob    |      +-----------+------------+
                    |  SuperviseTasks     |                  |
                    +---------+----------+                   |
                              |                              |
                              v                              v
                    +--------------------+      +------------------------+
                    |     TaskEngine      |      |      ToolRegistry      |
                    | plan/execute/verify |      |  22 registered tools   |
                    |  checkpoints/retry  |      +-----------+------------+
                    +---------+----------+                  |
                              |                             v
                              |                  +------------------------+
                              |                  |      OllamaService     |
                              |                  | streaming + embeddings |
                              |                  +-----------+------------+
                              v                              |
                    +--------------------+                   v
                    |  MySQL / SQLite    |          +------------------+
                    |  tasks, messages,  |          |   Ollama server  |
                    |  events, memory... |          |  (local models)  |
                    +--------------------+          +------------------+
```

---

## Request & data flow

### Chat message (streaming)

```
User types message
      |
      v
[ChatController] -- POST /api/v1/conversations/{id}/messages
      |
      v
[AgentService::run]
      |
      +--> save user Message (DB)
      +--> build context (history + memory + identity)
      |
      v
[OllamaService] -- stream /api/chat -------------------------+
      |                                                      |
      |  tokens + tool calls                                 |
      v                                                      |
[ToolRegistry] -- execute enabled tools                      |
      |  (file, shell, web, memory, ...)                     |
      +--> write tool result to `events` table              |
      |                                                      |
      +--> feed results back to model  --------------------- +
      |
      |  final answer
      v
save assistant Message (DB) + buffer state in cache (AgentRunState)
      |
      v
UI polls /api/v1/conversations/{id}/stream -> renders tokens, thinking, tools
      |
      v
[OutcomeRecorder] -- verdict: passed / failed / unverified
```

### Task execution (durable)

```
POST /api/v1/engine/tasks
      |
      v
[TaskEngine::create] --> Task(status=pending) --> plan
      |
      v
[ExecuteTaskStepJob]  (one job per step, lock + heartbeat)
      |
      +--> run step via AgentService / tools
      +--> TaskCheckpoint (progress is durable)
      +--> on failure: FailureClassifier -> retry | wait | fail
      |
      v
[FinalizeTaskJob] --> [VerificationService] (independent pass)
      |                        |
      |                  pass  |  fail -> repair step
      v                        v
Task(status=completed)     Task(status=running/failed)
      ^
      |
[TaskSupervisor]  (every minute) -- recovers stalled tasks, renews heartbeats
```

### Scheduled / triggered work

```
cron (schedule:work, every minute)
      |
      +--> dispatch-scheduled-tasks --> [RunScheduledTaskJob] --> Mission/Task
      +--> check-triggers (5 min)    --> [CheckTriggersJob]    --> file/URL watches
      +--> refresh-proactive-findings (15 min)
      +--> supervise-tasks (1 min)   --> [TaskSupervisor]
      +--> daily-report (23:55)      --> [GenerateDailyReportJob]
```

---

## Task lifecycle

```
pending --> planning --> running <==> retrying --> verifying --> completed
                            |             |            |
                         paused         waiting      failed
                            \             |            /
                             +------------+-----------+
                                          |
                                      cancelled
```

Non-retryable failures park a task in `waiting` for human input rather than burning its
retry budget. Sudo-gated actions surface an approval prompt in the UI
(`agent-tasks` → approve/deny).

---

## Project structure

```
app/
  Services/Agent/        Agent loop, orchestration, identity, missions, tool registry
  Services/TaskEngine/   Task planning, execution, verification, recovery
  Services/Tools/        All agent tools (+ CredentialsFile reader, MediaLibrary)
  Services/Embedding/    Vector store + embeddings (RAG)
  Jobs/                  Queue jobs (task steps, scheduled tasks, supervisor)
  Models/                Eloquent models (Conversation, Task, Mission, Skill, ...)
  Http/Controllers/      Inertia pages + /api/v1 JSON + Settings controllers
  Http/Requests/Settings Form requests (profile, password, credentials, ...)
  Events/ Listeners/     Task/tool lifecycle events
resources/js/pages/      Inertia React pages (chat, tasks, missions, media, settings)
database/migrations/     Schema
routes/                  web, api, console (scheduler), settings
tests/                   PHPUnit unit + feature suites
config/agent.php         Working dir, credentials file, media root, tool toggles
```

---

## Tools

Registered agent tools (`app/Services/Tools/`):

`file` · `shell` · `web` · `browser` · `memory` · `document` · `skill` ·
`scheduled-task` · `project` · `trigger` · `mission` · `opencode` · `task-engine` ·
`google-sheets` · `docker` · `cloudflare` · `whatsapp` · `youtube` · `instagram` ·
`facebook` · `media-clip`

Enable/disable each with its `AGENT_ENABLE_*` environment flag.

---

## HTTP API

Authenticated JSON API under `/api/v1`:

- `conversations` — CRUD + message send + SSE streaming/cancel
- `files` — browse, read, write, create, mkdir, move, delete
- `tasks` — legacy task monitor
- `engine/tasks` — durable task engine (create, execute, pause, wait, cancel,
  resume, recover, replan, restart step, supervise)
- `skills` — skills and skill scripts
- `employee` — agent enhancement and creation
- `metrics`, `settings`

Public webhook receiver: `POST /api/webhooks/{triggerName}` (secret-validated).
Hermes3D runtime contract under `/api/hermes3d/*`. See `routes/api.php`.

---

## Testing, linting & type checking

```bash
php artisan test --compact        # run the suite (PHPUnit, MySQL: laraclaw_test)
composer test                     # lint check + full test run
vendor/bin/pint --dirty           # format changed PHP files
npm run lint:check                # ESLint
npm run types:check               # tsc --noEmit
npm run format:check              # Prettier
```

---

## Troubleshooting

**"Access denied for user 'root'@'localhost' (using password: NO)"**
Your `DB_PASSWORD` in `.env` is empty. Set it to match your MySQL user, then run
`php artisan config:clear`.

**A page returns 500 after changing `.env`**
Run `php artisan config:clear` (and `php artisan optimize:clear` if caches were built).

**Credentials disappear after a reboot / tmp cleanup**
The credentials file is pointing at `/tmp`. Move it to `~/.laraclaw/.env` and set
`AGENT_CREDENTIALS_FILE` accordingly.

**Frontend changes not visible**
Run `npm run dev` (or `npm run build`), or start everything with `composer run dev`.

**"Unable to locate file in Vite manifest"**
Run `npm run build` or start the Vite dev server.

---

## License

MIT.
