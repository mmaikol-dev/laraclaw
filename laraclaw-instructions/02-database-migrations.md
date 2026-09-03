# 02 — Database Migrations

## What to do
Create all database tables using `php artisan make:migration`. Run `php artisan migrate` after all migrations are created.

---

## Table: `conversations`
Stores each chat session.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| title | string | default "New conversation" |
| model | string | default "glm-5:cloud" |
| system_prompt | json nullable | per-conversation system override |
| total_tokens | integer | running token count, default 0 |
| is_archived | boolean | default false |
| timestamps | | |

---

## Table: `messages`
Stores every message in a conversation including tool call messages.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| conversation_id | FK → conversations | cascade delete |
| role | enum | `user`, `assistant`, `tool`, `system` |
| content | longText nullable | the text content |
| tool_calls | json nullable | array of tool calls the assistant made |
| tool_result | json nullable | structured result (optional, for display) |
| tool_name | string nullable | which tool produced this message |
| prompt_tokens | integer | default 0 |
| completion_tokens | integer | default 0 |
| tokens_per_second | float | default 0 |
| duration_ms | integer | default 0 |
| timestamps | | |

---

## Table: `task_logs`
A log of every tool execution. Used by the Task Monitor page.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| conversation_id | FK nullable → conversations | nullOnDelete |
| message_id | FK nullable → messages | nullOnDelete |
| tool_name | string | e.g. `read_file`, `run_command` |
| tool_input | json | arguments passed to the tool |
| tool_output | longText nullable | raw string output |
| status | enum | `pending`, `running`, `success`, `error` |
| error_message | string nullable | |
| duration_ms | integer | default 0 |
| timestamps | | |

---

## Table: `metric_snapshots`
One row per agent response. Powers the dashboard charts.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tokens_per_second | float | |
| prompt_tokens | integer | |
| completion_tokens | integer | |
| total_duration_ms | integer | |
| load_duration_ms | integer | |
| model | string | which model was used |
| tool_name | string nullable | if this snapshot is for a tool call |
| extra | json nullable | spare JSON for future use |
| recorded_at | timestamp | useCurrent() |
| timestamps | | |

---

## Table: `embedded_documents`
Tracks files that have been embedded into the vector store.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| filename | string | basename of the file |
| filepath | string | absolute path |
| mime_type | string nullable | |
| file_size | integer | bytes |
| chunk_count | integer | how many chunks it was split into |
| is_indexed | boolean | true when embedding is complete |
| indexed_at | timestamp nullable | |
| timestamps | | |

---

## Table: `embedding_chunks`
Each row is one text chunk with its embedding vector.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| embedded_document_id | FK → embedded_documents | cascade delete |
| chunk_index | integer | order of the chunk |
| content | text | the raw text of this chunk |
| embedding | json | the float array from qwen3-embedding |
| timestamps | | |

---

## Table: `agent_settings`
Key-value store for all agent configuration that is editable from the UI.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| key | string unique | e.g. `working_dir`, `enable_shell` |
| value | text nullable | stored as string, cast on read |
| type | string | `string`, `bool`, `int`, `json` |
| label | string | human-readable label for the Settings UI |
| description | string nullable | helper text shown in the UI |
| timestamps | | |

---

## Seeder: `AgentSettingsSeeder`
Create `database/seeders/AgentSettingsSeeder.php`.

It should insert these default rows into `agent_settings`:

| key | default value | type |
|---|---|---|
| working_dir | from env AGENT_WORKING_DIR | string |
| allowed_paths | from env AGENT_ALLOWED_PATHS | string |
| shell_timeout | 30 | int |
| max_file_size_mb | 10 | int |
| enable_shell | true | bool |
| enable_web | true | bool |
| temperature | 0.7 | string |
| context_length | 8192 | int |
| system_prompt | "You are LaraClaw, a helpful local AI agent running on the user's Linux machine..." | string |

Use `updateOrInsert` so running the seeder twice is safe.

Run with: `php artisan db:seed --class=AgentSettingsSeeder`
