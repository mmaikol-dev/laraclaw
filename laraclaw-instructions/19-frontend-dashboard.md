# 19 — Frontend: Dashboard Page

File: `resources/js/pages/Dashboard/DashboardPage.tsx`

---

## What this page does
Shows performance metrics and health information for the LaraClaw agent. Useful for monitoring how the model is performing over time.

---

## Data source
Single fetch from `GET /api/v1/metrics`. Refetch every 30 seconds automatically using `refetchInterval` in react-query.

---

## Layout
Three sections stacked vertically:
1. Status bar (Ollama health)
2. KPI stat cards row
3. Two charts side by side
4. Tool usage table

---

## Section 1 — Ollama status bar
A banner at the top showing:
- Green/red status dot
- `ollama_health.host` value
- Agent model name (`ollama_health.agent_model`)
- Embedding model name (`ollama_health.embedding_model`)
- Whether each model is available (green check or red X)
- If status is `error`, show the error message prominently in red

---

## Section 2 — KPI cards (one row, 4–6 cards)
| Card | Value | Notes |
|---|---|---|
| Avg tokens/sec | `avg_tokens_per_sec` | Show as `42.3 tok/s` |
| Avg latency | `avg_latency_ms` | Show as `1.2s` or `800ms` |
| Total tokens used | `total_tokens` | Format with commas |
| Total conversations | `total_conversations` | |
| Total messages | `total_messages` | |
| Task error rate | `task_error_rate` | Show as `3.2%`, red if > 10% |

---

## Section 3 — Charts (use Recharts)

### Tokens over time (LineChart)
- Data: `tokens_over_time` array — each point has `hour` and `tokens`
- X axis: hour label (format with date-fns, e.g. "14:00")
- Y axis: token count
- Show as a smooth line with dots
- Title: "Completion tokens per hour"

### Latency over time (LineChart)
- Data: `latency_over_time` array — each point has `hour` and `avg_ms`
- X axis: hour label
- Y axis: milliseconds
- Title: "Average response latency (ms)"

Both charts should be responsive (`ResponsiveContainer` with `width="100%"`).

---

## Section 4 — Tool usage table
Data: `tool_usage` array.

Columns:
| Column | Notes |
|---|---|
| Tool | Monospace badge |
| Total calls | |
| Avg duration | Format as ms or seconds |
| Errors | Red text if > 0 |
| Error rate | `errors / count * 100`% |

---

## Empty / loading state
While data is loading, show skeleton cards and chart placeholders.
If Ollama is offline (`status !== 'ok'`), show a prominent alert with instructions to start Ollama.
