# 03 — Eloquent Models

## What to do
Create all models with `php artisan make:model`. Define fillable fields, casts, and relationships as described below.

---

## Conversation
File: `app/Models/Conversation.php`

- Cast `system_prompt` as array, `is_archived` as boolean
- Relationship: `hasMany(Message::class)` ordered by `created_at`
- Relationship: `hasMany(TaskLog::class)` ordered latest first
- Helper method `toOllamaMessages()` — returns all user/assistant/tool messages formatted as an array of `['role' => ..., 'content' => ...]` for the Ollama API

---

## Message
File: `app/Models/Message.php`

- Cast `tool_calls` and `tool_result` as array
- Relationship: `belongsTo(Conversation::class)`
- Helper method `toOllamaFormat()` — returns the message as an Ollama API message array. If role is `assistant` and `tool_calls` is not empty, include `tool_calls` in the returned array.

---

## TaskLog
File: `app/Models/TaskLog.php`

- Cast `tool_input` as array
- Relationship: `belongsTo(Conversation::class)`, `belongsTo(Message::class)`
- Helper methods:
  - `markRunning()` — sets status to `running`
  - `markSuccess(string $output, int $durationMs)` — sets status, output, duration
  - `markError(string $error, int $durationMs)` — sets status, error message, duration

---

## MetricSnapshot
File: `app/Models/MetricSnapshot.php`

- Cast `recorded_at` as datetime, `extra` as array
- Static factory method `record(array $stats, string $model, ?string $toolName)` that creates a row from a stats array (keys: `tokens_per_second`, `prompt_tokens`, `completion_tokens`, `total_duration_ms`, `load_duration_ms`)

---

## EmbeddedDocument
File: `app/Models/EmbeddedDocument.php`

- Cast `is_indexed` as boolean, `indexed_at` as datetime
- Relationship: `hasMany(EmbeddingChunk::class)`

---

## EmbeddingChunk
File: `app/Models/EmbeddingChunk.php`

- Cast `embedding` as array
- Relationship: `belongsTo(EmbeddedDocument::class)`

---

## AgentSetting
File: `app/Models/AgentSetting.php`

- Static helper `get(string $key, mixed $default)` — fetches and casts a value by key. Cast by `type` column: `bool` uses `filter_var`, `int` casts to int, `json` decodes, otherwise returns raw string.
- Static helper `set(string $key, mixed $value)` — updates the value by key
- Static helper `allAsArray()` — returns all settings as a keyed array for the Settings API response
