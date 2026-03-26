# 16 — Frontend: Chat Page

File: `resources/js/pages/Chat/ChatPage.tsx`

---

## What this page does
The main agent interface. Shows a conversation thread, streams tokens in real time as the agent responds, and displays tool call cards inline.

---

## URL params
Uses `:id` from the URL. If no ID, show a welcome/empty state.

---

## Data fetching
- Use `useQuery` to fetch `GET /api/v1/conversations/:id` on load
- Invalidate and refetch after `agent.done` event fires

---

## Sending a message
1. User submits the input (Enter key or send button)
2. If no conversation ID yet: POST `/api/v1/conversations` to create one, navigate to `/chat/{id}`
3. POST `/api/v1/conversations/{id}/messages` with `{ message }` body
4. Response returns `{ channel }` — store it and start listening via `useEcho`

---

## WebSocket events to listen for
Use `useEcho(channel, { ... })` with these handlers:

| Event | Action |
|---|---|
| `agent.chunk` | Append `data.content` to a `streamingContent` state string |
| `agent.tool` | Upsert into an `activeTools` state array keyed by `data.id` |
| `agent.done` | Set `isStreaming = false`, clear streaming content and active tools, invalidate query |

---

## Message rendering
Each message in the thread:

- **User messages** — right-aligned bubble with primary background
- **Assistant messages** — left-aligned, render content as Markdown with syntax-highlighted code blocks
- **Tool messages** (`role = 'tool'`) — do not render directly; they are shown via tool call cards
- **Streaming assistant message** — show `streamingContent` as markdown with a blinking cursor `▋` appended while streaming

---

## Tool call cards
For each entry in `activeTools`, render a collapsible card:
- Header: tool icon (Wrench), tool name in monospace, status icon (spinning loader / green check / red X), duration in ms
- Expandable body: JSON-formatted input, then output text if available
- Cards update in place when the `agent.tool` event fires again with the result

---

## Input area
- `<Textarea>` that grows with content (min 1 row, max 5)
- Enter to send, Shift+Enter for newline
- Disabled while `isStreaming = true`
- Send button shows a spinner while streaming

---

## Auto-scroll
Scroll to the bottom of the message list whenever new messages or streaming content appears.

---

## Empty state (no conversation ID)
Show: LaraClaw name, a short description, and a "Start a conversation" button.

---

## Conversation list sidebar (optional enhancement)
If time allows, render the conversation list from `GET /api/v1/conversations` in a secondary panel. Allow clicking to navigate and show the new chat button.
