# 12 — Events & Jobs

## What to do
Create 3 broadcast events and 2 queue jobs using `php artisan make:event` and `php artisan make:job`.

---

## Events

All three events must implement `ShouldBroadcastNow` (not `ShouldBroadcast`) so they fire immediately without being queued.

---

### AgentChunkStreamed
File: `app/Events/AgentChunkStreamed.php`

Fired for every token chunk streamed from Ollama.

- Constructor: `string $channel`, `string $content`
- `broadcastOn()` → `new Channel($this->channel)`
- `broadcastAs()` → `'agent.chunk'`
- `broadcastWith()` → `['content' => $this->content]`

---

### AgentToolCalled
File: `app/Events/AgentToolCalled.php`

Fired twice per tool execution: once when it starts (status: running) and once when it finishes (status: success or error).

- Constructor: `string $channel`, `array $toolData`
- `broadcastOn()` → `new Channel($this->channel)`
- `broadcastAs()` → `'agent.tool'`
- `broadcastWith()` → `$this->toolData`

The `$toolData` array shape:
```
{
  id: int (TaskLog id),
  tool_name: string,
  input: object,
  output?: string,
  status: 'running' | 'success' | 'error',
  duration_ms?: int
}
```

---

### AgentFinished
File: `app/Events/AgentFinished.php`

Fired when the agent produces its final response with no more tool calls.

- Constructor: `string $channel`, `int $messageId`, `array $stats`
- `broadcastOn()` → `new Channel($this->channel)`
- `broadcastAs()` → `'agent.done'`
- `broadcastWith()` → `['message_id' => $this->messageId, 'stats' => $this->stats]`

---

## Jobs

### RunAgentJob
File: `app/Jobs/RunAgentJob.php`

- Public properties: `int $timeout = 300`, `int $tries = 1`
- Constructor: `int $conversationId`, `string $userMessage`
- `handle(AgentService $agent)`: fetch the conversation, build channel name as `"conversation.{$conversationId}"`, call `$agent->run()`
- Dispatch on the `agent` queue: `RunAgentJob::dispatch($id, $message)->onQueue('agent')`

---

### IndexDocumentJob
File: `app/Jobs/IndexDocumentJob.php`

- Public properties: `int $timeout = 180`, `int $tries = 2`
- Constructor: `string $filepath`
- `handle(EmbeddingService $service)`: call `$service->indexFile($this->filepath)`
- Dispatch on the `embeddings` queue: `IndexDocumentJob::dispatch($path)->onQueue('embeddings')`

---

## Horizon Queue Config
In `config/horizon.php`, set the supervisor to watch all three queues:

```
'queue' => ['default', 'agent', 'embeddings']
```
