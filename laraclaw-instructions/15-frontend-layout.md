# 15 — Frontend Layout

## What to do
Set up the React app entry point, routing, sidebar layout, and shared hooks/utilities.

---

## Entry point: `resources/js/app.tsx`
- Wrap the app in `QueryClientProvider` (react-query) and `BrowserRouter`
- Define routes using React Router v6 with a nested layout:
  - `/` → redirect to `/chat`
  - `/chat` and `/chat/:id` → ChatPage
  - `/files` → FilesPage
  - `/tasks` → TasksPage
  - `/dashboard` → DashboardPage
  - `/settings` → SettingsPage
  - `/memory` → MemoryPage
- All page routes are children of `AppLayout` which renders a `<Outlet />`

---

## AppLayout: `resources/js/components/layout/AppLayout.tsx`
A persistent sidebar + main content area.

**Sidebar contains:**
- LaraClaw logo/name at the top
- A collapse/expand toggle button
- A "New chat" button — on click, POST `/api/v1/conversations` and navigate to `/chat/{id}`
- Navigation links to all pages using `NavLink` (highlights active route)
- A small Ollama status indicator at the bottom — green dot if `ollama_health.status === 'ok'`, red if not. Show the model name next to the dot when expanded.

**Sidebar behaviour:**
- Collapsible to icon-only mode (store state in `useState`)
- When collapsed, show only icons; when expanded, show icons + labels

**Nav items and their icons:**
| Page | Icon |
|---|---|
| Chat | MessageSquare |
| Files | FolderOpen |
| Tasks | Activity |
| Dashboard | BarChart2 |
| Memory | Database |
| Settings | Settings |

---

## Shared hook: `resources/js/hooks/useOllamaHealth.ts`
- Use `useQuery` from react-query
- Fetch `/api/v1/metrics` and return `data.ollama_health`
- Refetch every 30 seconds

---

## Shared hook: `resources/js/hooks/useEcho.ts`
- Create a singleton Laravel Echo instance using Reverb (Pusher-compatible)
- Read connection config from `import.meta.env.VITE_REVERB_*`
- Export a `useEcho(channel, eventsMap)` hook that:
  - Subscribes to the given channel on mount
  - Listens to each event name in `eventsMap` (prepend `.` to the event name — Laravel broadcasts with a dot prefix)
  - Unsubscribes on unmount using `echo.leaveChannel(channel)`
  - Does nothing if `channel` is null

---

## API client: `resources/js/lib/api.ts`
- Create an axios instance with `baseURL: '/api/v1'`
- Set `Content-Type: application/json` and `Accept: application/json` headers
- Export as default

---

## Vite config
In `vite.config.ts`, ensure the dev server proxies `/api` to `http://localhost:8000` so API calls work during `npm run dev`.

---

## bootstrap.ts
Configure Laravel Echo in `resources/js/bootstrap.ts`:
- Import Pusher and assign to `window.Pusher`
- Create and assign `window.Echo` using the Reverb broadcaster
- Read all connection params from `import.meta.env.VITE_REVERB_*`
