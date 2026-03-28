# LaraClaw 🐾

A local AI agent dashboard that runs entirely on your own machine. No cloud, no subscriptions — just your laptop running a powerful AI assistant that can read files, execute shell commands, search the web, and improve itself over time.

![LaraClaw Chat](https://placeholder.example/screenshot.png)

---

## What it does

LaraClaw is a web UI that gives you a chat interface to a local AI agent powered by [Ollama](https://ollama.com). You talk to it like a normal chatbot, but unlike a normal chatbot it can actually *do things* on your computer.

**It has direct access to your local machine** — your files, folders, and terminal. It can navigate your directory tree, read any file you point it at, write new files, and run shell commands, all from the chat window. No copy-pasting files into a chatbox.

**You can ask it things like:**

- *"Read my project files and explain what this codebase does"*
- *"Search the web for the latest news on X and summarise it"*
- *"Write a Python script that processes this CSV file and run it"*
- *"Look at my dashboard page and improve the UI"*
- *"Crawl this documentation site and answer my question about it"*
- *"Go through every file in ~/Documents and tell me what needs to be organised"*
- *"Find all TODO comments across my codebase and list them"*

It figures out what tools to use, uses them, and reports back — without asking you what to do at every step.

---

## Features

### AI agent
- Runs fully locally via Ollama — your data never leaves your machine
- Streams responses and thinking in real time as the model works
- Shows every tool call as it happens so you can see exactly what it's doing
- Multi-step reasoning — it plans, executes, checks results, and continues

### Tools the agent can use
| Tool | What it does |
|------|-------------|
| **File** | Browse folders, read and write files anywhere on your machine |
| **Shell** | Run any shell command and get the output |
| **Web** | Search the web, extract content from URLs, crawl sites (via Tavily) |
| **Document** | Index and semantically search local documents |
| **Skill** | Read, create, and update its own skills (self-improvement) |

### Skills system
The agent has a library of reusable skills — step-by-step instructions it follows for specific types of tasks (coding, research, writing, etc.). You can create skills yourself, or the agent can write new ones and update existing ones as it learns better approaches. Skills are automatically injected into every conversation so the agent always knows what it's capable of.

### Chat UI
- Conversation list sidebar — all your chats in one place
- Real-time streaming — see words appear as the model generates them
- Collapsible thinking trace — click to see the model's internal reasoning
- Tool call cards — see every file read, shell command, and web search live
- Skill call cards — highlighted separately so you know when the agent is loading or creating a skill
- Full markdown rendering — headings, code blocks, tables, lists all render properly

### Files page
- VS Code-style file explorer for the agent's working directory
- Browse, read, and edit files directly from the browser
- File type icons with syntax-aware colour coding

### Tasks page
- Full history of every tool call the agent has ever made
- Filter by status, tool type, and conversation

### Dashboard
- Live metrics — messages sent, tools used, tokens generated
- At-a-glance view of recent activity

---

## Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.3 · Laravel 13 |
| Frontend | React 19 · TypeScript · Tailwind CSS v4 |
| Routing | Inertia.js v2 · Laravel Wayfinder |
| AI | Ollama (local LLM) |
| Agent model | `glm-5:cloud` (default) |
| Embeddings | `qwen3-embedding:0.6b` |
| Web search | Tavily API |
| Database | MySQL 8 |

---

## Quick start (Docker)

The easiest way — requires only [Docker Desktop](https://www.docker.com/products/docker-desktop).

```bash
curl -fsSL https://raw.githubusercontent.com/you/laraclaw/main/install.sh | bash
```

Then open **http://localhost:8080**.

That's it. The installer handles everything — PHP, MySQL, Ollama, and model downloads.

> **First run** downloads the AI models (~4 GB). This only happens once — models are cached in a Docker volume and reused on every restart.

### Managing the Docker install

```bash
# Start
docker compose up -d

# Stop
docker compose down

# View logs
docker compose logs -f

# Update to latest version
git pull && docker compose up -d --build
```

---

## Manual install (for developers)

**Requirements:** PHP 8.3, Composer, Node.js 20+, MySQL 8, [Ollama](https://ollama.com)

```bash
# 1. Clone
git clone https://github.com/you/laraclaw
cd laraclaw

# 2. Install dependencies
composer install
npm install

# 3. Environment
cp .env.example .env
php artisan key:generate

# 4. Configure .env
#    Set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#    Set TAVILY_API_KEY (optional, for web search)

# 5. Database
php artisan migrate

# 6. Pull Ollama models
ollama pull glm-5:cloud
ollama pull qwen3-embedding:0.6b

# 7. Start
composer run dev
```

Open **http://localhost:8000**.

---

## Configuration

All settings are in `.env`. The important ones:

```env
# Which model the agent uses
OLLAMA_AGENT_MODEL=glm-5:cloud

# Which model is used for document embeddings
OLLAMA_EMBEDDING_MODEL=qwen3-embedding:0.6b

# Default working directory for new files the agent creates
AGENT_WORKING_DIR=/tmp/laraclaw

# Comma-separated list of paths the agent is allowed to access.
# Add your home folder or project directories to give it broader access.
# Example: AGENT_ALLOWED_PATHS=/tmp/laraclaw,/home/yourname,/home/yourname/projects
AGENT_ALLOWED_PATHS=/tmp/laraclaw

# Web search (get a free key at app.tavily.com)
TAVILY_API_KEY=tvly-...

# Shell command timeout in seconds
AGENT_SHELL_TIMEOUT=30
```

You can also change the agent's system prompt, model, and temperature from the **Agent Settings** page in the UI.

---

## Changing the AI model

Any model available in Ollama works. Pull it first, then update `OLLAMA_AGENT_MODEL` in your `.env`:

```bash
ollama pull llama3.2
ollama pull qwen2.5:14b
ollama pull deepseek-r1:8b
```

Models with tool-calling support work best for the agent. Check [ollama.com/search](https://ollama.com/search) for available models.

---

## Skills

Skills are reusable instruction sets that tell the agent how to approach specific types of tasks. They live in the **Skills** page and are automatically included in every conversation.

The agent can:
- **Load** a skill when it recognises a relevant task
- **Create** a new skill when it discovers a useful pattern
- **Update** an existing skill when it finds a better approach

You can also write skills yourself — just go to Skills → New skill and describe what you want the agent to do step by step.

**Example skill — "debug-php-error":**
```
1. Read the error message and identify the file and line number
2. Read that file and the surrounding context (±20 lines)
3. Check recent git commits that touched that file
4. Search the web if the error message is a known library issue
5. Explain the root cause and propose a fix
6. Apply the fix and verify it resolves the error
```

---

## Privacy

Everything runs locally on your machine. Your conversations, files, and agent activity are stored in your local MySQL database. The only outbound network calls are:

- **Ollama** — to your local Ollama instance (no external calls)
- **Tavily** — only when the agent uses the web search tool (optional, requires an API key)

---

## License

MIT
