# LaraClaw on Android — Full Setup Guide
### Xiaomi Redmi Note 10 (M2101K6G) — Snapdragon 732G — 6GB RAM — MIUI 14

This is a complete record of how LaraClaw was installed and configured to run
fully locally on an Android phone using Termux, with Ollama for local AI inference.

---

## Device Specs

| Component | Details |
|---|---|
| Device | Xiaomi Redmi Note 10 (M2101K6G) |
| CPU | Snapdragon 732G, Octa-core 2.3GHz |
| RAM | 6GB + 2GB virtual |
| OS | Android 13, MIUI Global 14.0.8 |
| Inference | Ollama (local, CPU only) |

---

## Apps Installed

All installed from **F-Droid** (not Play Store):

- **Termux** — Linux terminal environment
- **Termux:Boot** — runs scripts on phone reboot
- **Termux:API** — gives Termux access to Android hardware

> After installing Termux:Boot, open it once then close it so it registers as a boot listener.

---

## Step 1 — Update Termux

```bash
pkg update && pkg upgrade -y
```

---

## Step 2 — Install Core Dependencies

```bash
pkg install -y php git curl unzip python
```

---

## Step 3 — Install Composer

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar $PREFIX/bin/composer
chmod +x $PREFIX/bin/composer
```

Verify:
```bash
composer --version
# Composer 2.9.5
```

---

## Step 4 — Install Supervisor

```bash
pip install supervisor
# Successfully installed supervisor-4.3.0
```

---

## Step 5 — Install Node.js

```bash
pkg install nodejs
# Node.js 25.8.2, npm 11.12.1
```

---

## Step 6 — Install Ollama

The standard install script requires root so we use the Termux package instead:

```bash
pkg install ollama
# ollama 0.20.5
```

---

## Step 7 — Clone LaraClaw

```bash
mkdir -p $PREFIX/var/run $PREFIX/var/log $PREFIX/etc/supervisor
cd ~
git clone https://github.com/mmaikol-dev/laraclaw.git
cd laraclaw
```

---

## Step 8 — Install PHP Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

---

## Step 9 — Environment Setup

```bash
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
nano .env
```

Key `.env` changes:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=sqlite
DB_DATABASE=/data/data/com.termux/files/home/laraclaw/database/database.sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_USERNAME=root
# DB_PASSWORD=

OLLAMA_HOST=http://127.0.0.1:11434
OLLAMA_AGENT_MODEL=glm-5:cloud
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
OLLAMA_TIMEOUT=120
OLLAMA_CONTEXT_LENGTH=8192
```

> Note: DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD must be commented out when using SQLite.
> There was a typo `sqllite` (double l) — make sure it's `sqlite`.

---

## Step 10 — Run Migrations

```bash
php artisan migrate --force
```

All tables created successfully including:
- users, cache, jobs
- personal_access_tokens
- agent_core_tables, messages, skills, skill_scripts
- projects, scheduled_tasks, agent_memories, agent_reports
- project_tasks, triggers

---

## Step 11 — Install Frontend Dependencies & Build

```bash
npm install
npm run build
```

> `npm run dev` fails on Android with `ENOSPC` (file watcher limit).
> Use `npm run build` instead — compiles assets once, no watching needed.

---

## Step 12 — Cache Configuration

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

To clear and rebuild cache after .env changes:
```bash
php artisan config:clear
php artisan config:cache
```

---

## Step 13 — Pull Ollama Models

```bash
ollama serve &
ollama pull qwen2.5:3b
ollama pull nomic-embed-text
```

| Model | Size | Purpose |
|---|---|---|
| qwen2.5:3b | ~2GB | Main agent model |
| nomic-embed-text | ~274MB | Embeddings for memory/search |

---

## Step 14 — Supervisor Configuration

Create config file:
```bash
nano $PREFIX/etc/supervisor/supervisord.conf
```

Paste this content:

```ini
[unix_http_server]
file=/data/data/com.termux/files/usr/var/run/supervisor.sock

[supervisord]
logfile=/data/data/com.termux/files/usr/var/log/supervisord.log
logfile_maxbytes=10MB
logfile_backups=3
loglevel=info
pidfile=/data/data/com.termux/files/usr/var/run/supervisord.pid
nodaemon=false

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///data/data/com.termux/files/usr/var/run/supervisor.sock

[program:laraclaw-serve]
command=php artisan serve --host=0.0.0.0 --port=8000
directory=/data/data/com.termux/files/home/laraclaw
autostart=true
autorestart=true
startretries=5
stderr_logfile=/data/data/com.termux/files/usr/var/log/laraclaw-serve.err.log
stdout_logfile=/data/data/com.termux/files/usr/var/log/laraclaw-serve.out.log

[program:laraclaw-queue]
command=php artisan queue:work --sleep=3 --tries=3 --timeout=90
directory=/data/data/com.termux/files/home/laraclaw
autostart=true
autorestart=true
startretries=5
stderr_logfile=/data/data/com.termux/files/usr/var/log/laraclaw-queue.err.log
stdout_logfile=/data/data/com.termux/files/usr/var/log/laraclaw-queue.out.log

[program:laraclaw-scheduler]
command=php artisan schedule:work
directory=/data/data/com.termux/files/home/laraclaw
autostart=true
autorestart=true
startretries=5
stderr_logfile=/data/data/com.termux/files/usr/var/log/laraclaw-scheduler.err.log
stdout_logfile=/data/data/com.termux/files/usr/var/log/laraclaw-scheduler.out.log
```

---

## Step 15 — Auto-start on Boot

```bash
mkdir -p ~/.termux/boot
nano ~/.termux/boot/start-laraclaw.sh
```

Paste:

```bash
#!/data/data/com.termux/files/usr/bin/sh

# Keep Termux awake
termux-wake-lock

# Wait for system to settle
sleep 10

# Start Ollama
ollama serve &

# Wait for Ollama to be ready
sleep 5

# Start Supervisor (manages Laravel serve, queue, scheduler)
supervisord -c /data/data/com.termux/files/usr/etc/supervisor/supervisord.conf
```

Make executable:
```bash
chmod +x ~/.termux/boot/start-laraclaw.sh
```

---

## Step 16 — Disable Battery Optimization

Critical — Android will kill Termux without this:

1. Settings → Apps → Termux → Battery → **Unrestricted**
2. Settings → Apps → Termux:Boot → Battery → **Unrestricted**

---

## Step 17 — Access the App

Open Chrome on the phone and go to:
```
http://localhost:8000
```

> Use `localhost:8000` not `0.0.0.0:8000` — Chrome blocks the `crypto.randomUUID` 
> API on `0.0.0.0` for security reasons, causing a JavaScript error.

To install as PWA:
1. Tap the three-dot menu in Chrome
2. Select **"Add to Home Screen"**
3. LaraClaw now appears as an app icon

---

## Managing Processes

```bash
# Check status
supervisorctl -c $PREFIX/etc/supervisor/supervisord.conf status

# Restart all
supervisorctl -c $PREFIX/etc/supervisor/supervisord.conf restart all

# Restart specific process
supervisorctl -c $PREFIX/etc/supervisor/supervisord.conf restart laraclaw-serve

# View logs
tail -f $PREFIX/var/log/laraclaw-serve.out.log
tail -f $PREFIX/var/log/laraclaw-queue.out.log
```

---

## Issues Encountered & Fixes

| Issue | Fix |
|---|---|
| Ollama install script requires root | Used `pkg install ollama` instead |
| `npm run dev` fails with ENOSPC | Use `npm run build` instead |
| `crypto.randomUUID is not a function` | Access via `localhost:8000` not `0.0.0.0:8000` |
| DB_CONNECTION typo `sqllite` | Fixed to `sqlite` in `.env` |
| .env changes not reflecting | Run `php artisan config:clear && php artisan config:cache` |
| Ollama binary from GitHub not working | Standard Linux binary needs glibc, Termux uses bionic |

---

## Models on This Phone

| Model | Size | Status |
|---|---|---|
| glm-5:cloud | — | ✅ Active |
| nomic-embed-text | ~274MB | ✅ Installed |
