# 01 — Project Setup

## What to do
Scaffold a fresh Laravel 11 project named `laraclaw` with the React + Shadcn/UI Breeze starter kit, then install all required packages.

## Steps

### 1. Create the Laravel project
```
composer create-project laravel/laravel laraclaw
cd laraclaw
```

### 2. Set APP_NAME in .env
```
APP_NAME=LaraClaw
```

### 3. Install Breeze with React + Shadcn starter kit
```
composer require laravel/breeze --dev
php artisan breeze:install react --typescript --shadcn
```

### 4. Install extra Composer packages
| Package | Why |
|---|---|
| `laravel/reverb` | WebSocket server for streaming agent tokens to the frontend |
| `laravel/horizon` | Dashboard + supervisor for Redis queues |
| `guzzlehttp/guzzle` | Streaming HTTP client for Ollama (Laravel Http facade can't stream) |
| `smalot/pdfparser` | Extract text from PDF files for the document tool |

### 5. Install extra npm packages
| Package | Why |
|---|---|
| `@monaco-editor/react` | Code editor embedded in the file explorer page |
| `recharts` | Charts on the dashboard page |
| `laravel-echo` + `pusher-js` | WebSocket client to receive streaming agent tokens |
| `@tanstack/react-query` | Server state management for all API calls |
| `axios` | HTTP client for API calls |
| `react-markdown` | Render markdown in chat messages |
| `react-syntax-highlighter` + `@types/react-syntax-highlighter` | Syntax-highlighted code blocks in chat |
| `date-fns` | Date formatting throughout the UI |

### 6. Publish package configs
```
php artisan reverb:install
php artisan horizon:install
```

### 7. Configure .env
Copy `.env.example` to `.env`, run `php artisan key:generate`, then add these blocks:

```
# Ollama
OLLAMA_HOST=http://127.0.0.1:11434
OLLAMA_AGENT_MODEL=glm-5:cloud
OLLAMA_EMBEDDING_MODEL=qwen3-embedding:0.6b
OLLAMA_TIMEOUT=120
OLLAMA_CONTEXT_LENGTH=8192

# Agent safety
AGENT_WORKING_DIR=/home/YOUR_USERNAME/workspace
AGENT_ALLOWED_PATHS=/home/YOUR_USERNAME/workspace,/tmp/laraclaw
AGENT_SHELL_TIMEOUT=30
AGENT_MAX_FILE_SIZE_MB=10
AGENT_MAX_OUTPUT_LINES=500
AGENT_ENABLE_SHELL=true
AGENT_ENABLE_WEB=true

# Brave Search
BRAVE_API_KEY=your_key_here
BRAVE_SEARCH_ENDPOINT=https://api.search.brave.com/res/v1/web/search
BRAVE_MAX_RESULTS=5

# Laravel Reverb
REVERB_APP_ID=laraclaw
REVERB_APP_KEY=laraclaw-key
REVERB_APP_SECRET=laraclaw-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# Vite must expose these to the frontend
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Connections
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

### 8. Create the SQLite database and workspace folders
```
touch database/database.sqlite
mkdir -p ~/workspace
mkdir -p /tmp/laraclaw
php artisan migrate
```

### 9. Create a custom service provider
Create `app/Providers/AgentServiceProvider.php` and register it in `bootstrap/providers.php`.

This provider binds the following as **singletons**:
- `OllamaService`
- `EmbeddingService`
- `VectorStore`
- `ToolRegistry` — also calls `$registry->register(...)` for all four tools inside the binding closure
- `AgentService`

### 10. Running the app (4 terminals)
```
php artisan serve          # Laravel API on :8000
php artisan reverb:start   # WebSocket server on :8080
php artisan horizon        # Queue worker
npm run dev                # Vite frontend on :5173
```

Access LaraClaw at: http://localhost:8000
Horizon dashboard at: http://localhost:8000/horizon
