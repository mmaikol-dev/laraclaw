# 09 — WebTool (Brave Search API)

## What to do
Create `app/Services/Tools/WebTool.php` extending `BaseTool`.
Also add a `brave` entry to `config/services.php`.

---

## Tool name
`web`

## Actions
| Action | What it does |
|---|---|
| `search` | Query Brave Search API and return formatted results |
| `fetch` | Download and clean a URL's content for the agent to read |

## Parameters schema
Required: `action`
Optional: `query` (for search), `url` (for fetch), `max_results` (int, 1–10, default 5)

---

## isEnabled
Return `(bool) AgentSetting::get('enable_web', true)`.

---

## config/services.php addition
Add a `brave` key with:
- `api_key` from `env('BRAVE_API_KEY')`
- `endpoint` from `env('BRAVE_SEARCH_ENDPOINT')`
- `max_results` from `env('BRAVE_MAX_RESULTS', 5)` cast to int

---

## search action
- Validate that `BRAVE_API_KEY` is not empty — throw a clear error if missing telling the user to set it in `.env`
- GET `https://api.search.brave.com/res/v1/web/search` with query params `q` and `count`
- Required headers: `Accept: application/json`, `Accept-Encoding: gzip`, `X-Subscription-Token: {api_key}`
- Use Laravel's `Http` facade with a timeout
- Parse `data['web']['results']` from the response
- Format each result as:
  ```
  1. Title
     URL: https://...
     Description text
     Published: date (if available)
  ```
- Return "No results found" if the array is empty

## fetch action
- Validate the URL with `filter_var(FILTER_VALIDATE_URL)`
- Block local/private hosts: localhost, 127.x, 192.168.x, 10.x, 172.16.x, ::1, 0.0.0.0
- GET the URL using Laravel `Http` with a `User-Agent: LaraClaw-Agent/1.0` header
- If the `Content-Type` response header contains `html`:
  - Strip `<script>`, `<style>`, `<nav>`, `<footer>` tags with regex
  - Convert block elements (`p`, `div`, `h1-h6`, `li`, `br`) to newlines
  - Strip remaining HTML tags with `strip_tags()`
  - Decode HTML entities
  - Collapse multiple spaces and blank lines
- Prepend the output with `=== Content from {url} ===`
- Call `$this->truncate($output, 300)` — web pages can be very long

---

## Getting a Brave API Key
1. Sign up at https://api.search.brave.com/
2. Free tier: 2,000 queries/month
3. Set `BRAVE_API_KEY=your_key` in `.env`
