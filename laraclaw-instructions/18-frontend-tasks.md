# 18 — Frontend: Task Monitor Page

File: `resources/js/pages/Tasks/TasksPage.tsx`

---

## What this page does
A live feed of every tool call the agent has ever made. Shows status, timing, input/output, and aggregate stats. Useful for debugging and auditing agent behaviour.

---

## Layout
- Top row: 4 stat cards
- Below: filter bar + task table

---

## Stat cards (fetch from GET /api/v1/tasks/stats)
| Card | Value |
|---|---|
| Total tasks | `stats.total` |
| Successful | `stats.success` |
| Errors | `stats.error` |
| Today | `stats.today` |

---

## Filter bar
- Dropdown to filter by **status**: All, pending, running, success, error
- Dropdown to filter by **tool**: All, file, shell, web, document
- These update query params and refetch the task list

---

## Task table
Fetch from `GET /api/v1/tasks?status=&tool=` (paginated, 50 per page).

Columns:
| Column | Notes |
|---|---|
| Tool | Monospace badge with tool name, coloured by tool type |
| Status | Coloured pill: blue=running, green=success, red=error, gray=pending |
| Input | Short preview of the input JSON (truncate after ~60 chars) |
| Duration | In ms, formatted as `123ms` or `1.2s` |
| Conversation | Linked conversation title (click goes to `/chat/{id}`) |
| Time | Relative time (e.g. "2 minutes ago") using date-fns |

### Row expansion
Clicking a row expands it inline to show:
- Full formatted JSON of `tool_input`
- Full `tool_output` in a scrollable pre block
- Error message if status is error

---

## Tool usage breakdown (fetch from GET /api/v1/tasks/stats)
Below the table, show a small breakdown for each tool:
- Tool name
- Call count
- Average duration
- Error count

---

## Live updates
Subscribe to a global channel (e.g. `tasks`) via Echo to receive new task events as the agent runs in other tabs. When a new `agent.tool` event fires, prepend the new task to the table or update an existing row by ID.

Alternatively: set a `refetchInterval` of 3 seconds on the query as a simpler approach.

---

## Pagination
Show "Load more" button or a simple page navigator at the bottom of the table.
