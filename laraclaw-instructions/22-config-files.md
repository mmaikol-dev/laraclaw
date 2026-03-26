# 22 — Config Files

## What to do
Create or update these Laravel config files.

---

## config/ollama.php
Create this file from scratch. Read all values from `.env`:

| Key | Env var | Default |
|---|---|---|
| `host` | OLLAMA_HOST | `http://127.0.0.1:11434` |
| `agent_model` | OLLAMA_AGENT_MODEL | `glm-5:cloud` |
| `embedding_model` | OLLAMA_EMBEDDING_MODEL | `qwen3-embedding:0.6b` |
| `timeout` | OLLAMA_TIMEOUT (cast int) | `120` |
| `context_length` | OLLAMA_CONTEXT_LENGTH (cast int) | `8192` |

---

## config/agent.php
Create this file from scratch. Read all values from `.env`:

| Key | Env var | Default |
|---|---|---|
| `working_dir` | AGENT_WORKING_DIR | `/tmp/laraclaw` |
| `allowed_paths` | AGENT_ALLOWED_PATHS | `''` |
| `shell_timeout` | AGENT_SHELL_TIMEOUT (cast int) | `30` |
| `max_file_size_mb` | AGENT_MAX_FILE_SIZE_MB (cast int) | `10` |
| `max_output_lines` | AGENT_MAX_OUTPUT_LINES (cast int) | `500` |
| `enable_shell` | AGENT_ENABLE_SHELL (cast bool) | `true` |
| `enable_web` | AGENT_ENABLE_WEB (cast bool) | `true` |
| `temperature` | — | `0.7` |

---

## config/services.php
Add a `brave` entry to the existing file:

| Key | Env var | Default |
|---|---|---|
| `api_key` | BRAVE_API_KEY | `''` |
| `endpoint` | BRAVE_SEARCH_ENDPOINT | `https://api.search.brave.com/res/v1/web/search` |
| `max_results` | BRAVE_MAX_RESULTS (cast int) | `5` |

---

## config/horizon.php
Update the `environments` section so the local supervisor watches all three queues:

```
'local' => [
    'supervisor-1' => [
        'connection'  => 'redis',
        'queue'       => ['default', 'agent', 'embeddings'],
        'balance'     => 'simple',
        'processes'   => 3,
        'tries'       => 1,
        'timeout'     => 300,
    ],
],
```

---

## config/broadcasting.php
Ensure Reverb is configured as a connection. The `reverb` key should already exist after running `php artisan reverb:install`. Verify it reads from the correct env vars (`REVERB_APP_ID`, `REVERB_APP_KEY`, etc.).

---

## config/queue.php
The `redis` connection should already exist. Verify `QUEUE_CONNECTION=redis` in `.env` is picked up correctly. No changes needed unless the default config is missing the `redis` driver.
