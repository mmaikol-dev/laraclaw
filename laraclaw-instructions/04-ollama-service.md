# 04 — OllamaService

## What to do
Create `app/Services/Agent/OllamaService.php` and `config/ollama.php`.

This service is the only place in the app that talks to Ollama's HTTP API.

---

## config/ollama.php
Read these values from `.env`:
- `host` — OLLAMA_HOST
- `agent_model` — OLLAMA_AGENT_MODEL
- `embedding_model` — OLLAMA_EMBEDDING_MODEL
- `timeout` — OLLAMA_TIMEOUT (cast to int)
- `context_length` — OLLAMA_CONTEXT_LENGTH (cast to int)

---

## OllamaService — constructor
Read all values from `config('ollama.*')` and store as private properties.
Make `agentModel` and `embeddingModel` public so AgentService can log them.

---

## Method: `chat(array $messages, array $tools, array $options): array`
- Non-streaming chat completion
- POST to `{host}/api/chat` with `stream: false`
- Include `tools` only if the array is not empty
- Merge default options (`num_ctx`, `temperature`) with passed `$options`
- Throw a `RuntimeException` if the response fails
- Return the decoded JSON response array

---

## Method: `chatStream(array $messages, array $tools, array $options): Generator`
- Streaming chat — this **must** use Guzzle directly (not the Laravel Http facade) because it needs true HTTP streaming
- POST to `{host}/api/chat` with `stream: true`
- Read the response body line by line
- Decode each line as JSON
- Yield typed chunks:
  - `['type' => 'content', 'content' => string]` for each text token
  - `['type' => 'tool_call', 'tool_call' => array]` for each tool call in `message.tool_calls`
  - `['type' => 'done', 'stats' => array]` when `done === true` — stats must include: `prompt_tokens`, `completion_tokens`, `total_duration_ms`, `load_duration_ms`, `tokens_per_second`
- Calculate `tokens_per_second` from `eval_count / (eval_duration / 1e9)`
- Ollama durations come in nanoseconds — convert to ms for stats

---

## Method: `embed(string|array $input): array`
- POST to `{host}/api/embed`
- Use `embeddingModel`
- Accept a single string or array of strings (normalise to array before sending)
- Return the `embeddings` array from the response (array of float arrays)
- Throw `RuntimeException` on failure

---

## Method: `healthCheck(): array`
- GET `{host}/api/tags` with a 5 second timeout
- Return a status array with:
  - `status`: `'ok'` or `'error'`
  - `host`, `agent_model`, `embedding_model`
  - `agent_available`: bool — whether the agent model name prefix appears in the model list
  - `embedding_available`: bool — same for embedding model
  - `available_models`: array of model name strings
  - `message`: error string if status is `'error'`
- Catch all exceptions and return error status instead of throwing
