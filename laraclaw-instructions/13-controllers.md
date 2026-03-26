# 13 — Controllers

## What to do
Create all controllers with `php artisan make:controller`. Every method returns `response()->json()`.

---

## ChatController
File: `app/Http/Controllers/ChatController.php`

| Method | Route | What it does |
|---|---|---|
| `index()` | GET /conversations | List all non-archived conversations with message count and last updated |
| `store()` | POST /conversations | Create a new conversation, return it |
| `show(Conversation $conv)` | GET /conversations/{id} | Return the conversation + all messages formatted for the frontend |
| `sendMessage(Request $req, Conversation $conv)` | POST /conversations/{id}/messages | Validate `message` field, dispatch `RunAgentJob`, return `{status, channel}` |
| `updateTitle(Request $req, Conversation $conv)` | PATCH /conversations/{id}/title | Validate and update the title |
| `destroy(Conversation $conv)` | DELETE /conversations/{id} | Set `is_archived = true` |

**sendMessage** — auto-title the conversation from the first 60 characters of the first user message if this is the first message in the conversation.

**show** — messages should include: `id`, `role`, `content`, `tool_calls`, `tool_name`, `stats` (tokens_per_second, duration_ms), `created_at` as ISO string.

---

## FileController
File: `app/Http/Controllers/FileController.php`

| Method | Route | What it does |
|---|---|---|
| `browse(Request $req)` | GET /files/browse | List a directory. Accepts `?path=`. Default to `working_dir` from settings. Return `{path, parent, items[]}` |
| `read(Request $req)` | GET /files/read | Read file content. Accepts `?path=`. Check size limit. Return `{path, content, extension, size}` |
| `write(Request $req)` | POST /files/write | Overwrite file with `{path, content}` body. Create parent dirs. Return `{status, path}` |
| `createFile(Request $req)` | POST /files/create | Create a new empty file. Error if exists. |
| `createDirectory(Request $req)` | POST /files/mkdir | Create directory recursively. |
| `delete(Request $req)` | DELETE /files | Delete file or directory recursively. Accepts `?path=`. |

Each `items[]` entry in `browse` must include: `name`, `path`, `type` (file/directory), `size` (null for dirs), `extension` (null for dirs), `modified_at`.
Sort: directories first, then files, both alphabetically.

---

## TaskController
File: `app/Http/Controllers/TaskController.php`

| Method | Route | What it does |
|---|---|---|
| `index(Request $req)` | GET /tasks | Paginated list (50/page) filtered by optional `?status=`, `?tool=`, `?conversation_id=`. Include conversation title. |
| `show(TaskLog $task)` | GET /tasks/{id} | Single task with conversation. |
| `stats()` | GET /tasks/stats | Aggregate stats: total, success, error, running counts, today count, breakdown by tool (count + avg duration). |

---

## MetricsController
File: `app/Http/Controllers/MetricsController.php`

Inject `OllamaService` via constructor.

| Method | Route | What it does |
|---|---|---|
| `index()` | GET /metrics | Full metrics snapshot for the dashboard |

Response shape:
```
{
  ollama_health: { ...from OllamaService::healthCheck() },
  avg_tokens_per_sec: float,
  avg_latency_ms: int,
  total_tokens: int,
  total_conversations: int,
  total_messages: int,
  total_tasks: int,
  task_error_rate: float (percent),
  tokens_over_time: [ {hour, tokens} ] last 24 hours,
  latency_over_time: [ {hour, avg_ms} ] last 24 hours,
  tool_usage: [ {tool_name, count, avg_ms, errors} ]
}
```

For `tokens_over_time` and `latency_over_time`, group `metric_snapshots` by hour using SQLite's `strftime('%Y-%m-%d %H:00', created_at)`.

---

## SettingsController
File: `app/Http/Controllers/SettingsController.php`

| Method | Route | What it does |
|---|---|---|
| `index()` | GET /settings | Return `AgentSetting::allAsArray()` |
| `update(Request $req)` | POST /settings | Accept `{settings: {key: value, ...}}`, call `AgentSetting::set()` for each, return `{status: 'saved'}` |

---

## MemoryController
File: `app/Http/Controllers/MemoryController.php`

| Method | Route | What it does |
|---|---|---|
| `index()` | GET /memory | List all embedded documents with chunk count |
| `store(Request $req)` | POST /memory | Validate `path`, check file exists, dispatch `IndexDocumentJob`, return 202 |
| `destroy(EmbeddedDocument $doc)` | DELETE /memory/{id} | Delete chunks and document row |
| `search(Request $req)` | POST /memory/search | Validate `query`, call `EmbeddingService::search()`, return results |
