# 11 — AgentService

## What to do
Create `app/Services/Agent/AgentService.php`.

This is the heart of LaraClaw — the think → tool → observe loop.

Depends on: `OllamaService`, `ToolRegistry`

---

## Main method: `run(Conversation $conversation, string $userMessage, string $channelName): Message`

This method runs the full agent loop and broadcasts events to the frontend in real time.

### Step 1 — Save the user message
Create a `Message` row with `role = 'user'` and the user's content.

### Step 2 — Build the message history
- Start with a system message using `AgentSetting::get('system_prompt')`
- Append all existing conversation messages using `$conversation->toOllamaMessages()`

### Step 3 — Get tools
Call `$this->tools->toOllamaTools()` to get the Ollama-formatted tool list.

### Step 4 — Agentic loop (max 10 iterations)
Loop until the model gives a final answer with no tool calls, or until 10 iterations.

Each iteration:

**a) Stream the model response**
Call `OllamaService::chatStream()`. Collect:
- All `content` chunks into a `$fullContent` string — broadcast each token with `broadcast(new AgentChunkStreamed($channelName, $chunk))`
- All `tool_call` chunks into a `$toolCalls` array
- The `done` stats into a `$stats` array

**b) No tool calls → final answer**
- Save the assistant message with content and stats (tokens, duration)
- Call `MetricSnapshot::record($stats, $model)`
- Increment `conversation.total_tokens`
- Broadcast `AgentFinished` event
- Break the loop and return the saved message

**c) Tool calls found → execute each one**
1. Save an assistant `Message` with `role = 'assistant'` and `tool_calls` set
2. Append this assistant turn to `$history`
3. For each tool call in the array:
   - Create a `TaskLog` row with `status = 'running'`
   - Broadcast `AgentToolCalled` event with status `running`
   - Call `ToolRegistry::execute($toolName, $arguments)`
   - Update the `TaskLog` via `markSuccess()` or `markError()`
   - Broadcast `AgentToolCalled` event again with the result and final status
   - Record a `MetricSnapshot` for the tool call duration
   - Save a `Message` row with `role = 'tool'`, the output as content, and `tool_name`
   - Append a tool result message to `$history`
4. Continue to the next loop iteration (the model will now see the tool results)

### Step 5 — Max iterations fallback
If the loop exits after 10 iterations without a final answer, save a fallback assistant message explaining the limit was reached and broadcast `AgentFinished`.

---

## Broadcast channels
Use the `$channelName` string (e.g. `conversation.{id}`) as the channel for all three event types.

---

## Tool call argument parsing
Ollama may return `arguments` as either a JSON string or an already-decoded array. Always check with `is_string()` and decode if needed before passing to `execute()`.
