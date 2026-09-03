# 23 — Testing & Verification

## What to do
Use these manual checks to verify each layer of LaraClaw is working before moving to the next.

---

## 1. Verify Ollama is running

```bash
curl http://localhost:11434/api/tags
```

You should see a JSON list of models including `glm-5:cloud` and `qwen3-embedding:0.6b`.

If not: run `ollama serve` in a terminal, then `ollama pull glm-5:cloud` and `ollama pull qwen3-embedding:0.6b`.

---

## 2. Verify the health endpoint

Start Laravel (`php artisan serve`) and visit:

```
GET http://localhost:8000/api/v1/metrics
```

Check the `ollama_health` field in the response — `status` should be `"ok"` and both `agent_available` and `embedding_available` should be `true`.

---

## 3. Verify Redis is running

```bash
redis-cli ping
# should return PONG
```

If not: `sudo systemctl start redis` or `sudo service redis start`.

---

## 4. Verify Horizon starts

```bash
php artisan horizon
```

Should print "Horizon started successfully." Visit `http://localhost:8000/horizon` to see the dashboard.

---

## 5. Verify Reverb WebSocket

```bash
php artisan reverb:start
```

Should print `Starting server on 0.0.0.0:8080`.

---

## 6. Verify the database migrations

```bash
php artisan migrate:status
```

All migrations should show as "Ran".

---

## 7. Verify settings seeder ran

```bash
php artisan tinker
> App\Models\AgentSetting::get('system_prompt')
```

Should return the default LaraClaw system prompt string.

---

## 8. Smoke test the agent

1. Start all 4 processes (serve, reverb, horizon, vite)
2. Open http://localhost:8000
3. Start a new conversation
4. Send: `"List the files in /tmp/laraclaw"`
5. You should see:
   - Streaming tokens appear in real time in the chat
   - A tool call card appear for the `file` tool with action `list`
   - The agent's final response with the directory listing

---

## 9. Smoke test web search

Send: `"Search the web for Laravel 11 release notes"`

You should see:
- A `web` tool call card with action `search`
- Results from Brave Search in the tool output
- The agent summarising the results

If you see an error about missing API key, set `BRAVE_API_KEY` in `.env` and restart the server.

---

## 10. Smoke test document indexing

1. Create a test file: `echo "LaraClaw is a local AI agent built with Laravel." > /tmp/laraclaw/test.txt`
2. Go to the Memory page
3. Enter path `/tmp/laraclaw/test.txt` and click "Index file"
4. Wait for `is_indexed = true`
5. Search for "local AI agent" — the test chunk should appear as a result

---

## Common issues

| Problem | Fix |
|---|---|
| Agent never responds | Check Horizon is running and the `agent` queue has workers |
| WebSocket not connecting | Check Reverb is running on port 8080 and VITE_REVERB_* env vars are set |
| Tool calls return "access denied" | Check `AGENT_ALLOWED_PATHS` includes the directory you're trying to access |
| Embedding fails | Verify `qwen3-embedding:0.6b` is pulled: `ollama list` |
| Brave search 401 error | API key is invalid or not set in .env |
| SQLite database locked | Only one Laravel process should write at a time — stop duplicate `php artisan serve` instances |
