# LaraClaw — Claude Code Master Prompt

You are building **LaraClaw**, a local AI agent app for Linux.
Read every .md file in this folder before writing any code.
Implement each file in numbered order.

## Stack
- **Backend**: Laravel 11, PHP 8.2+
- **Frontend**: React 18 + TypeScript + Shadcn/UI (via Laravel Breeze React starter kit)
- **AI**: Ollama local API — `glm-5:cloud` (agent) + `qwen3-embedding:0.6b` (embeddings)
- **Realtime**: Laravel Reverb (WebSocket) + Laravel Horizon (queues via Redis)
- **Web Search**: Brave Search API
- **Database**: SQLite (dev)

## What You Are Building
A local AI agent dashboard named **LaraClaw** with:
- A streaming chat interface where the agent can call tools
- A file explorer (browse, read, edit, create, delete files on the Linux machine)
- A live task monitor showing every tool call the agent makes
- A performance dashboard (tokens/sec, latency, tool usage charts)
- A settings page to configure the agent
- A memory/documents page to manage embedded files

## Golden Rules
- The project name is **LaraClaw** everywhere — in UI text, config, app name, package name
- Ask before overwriting existing files
- Use `php artisan make:` commands to scaffold classes
- All React components are TypeScript (.tsx)
- All API responses return JSON
- Never hardcode secrets — always use .env + config()
- Follow PSR-12 for PHP and Prettier defaults for TypeScript
