# 14 — Routes

## What to do
Configure `routes/api.php`, `routes/web.php`, and `routes/channels.php`.

---

## routes/api.php
All routes are prefixed with `/v1`. No auth middleware for now (single-user local app).

Group the routes under `Route::prefix('v1')`:

```
Conversations:
  GET    /conversations
  POST   /conversations
  GET    /conversations/{conversation}
  POST   /conversations/{conversation}/messages
  PATCH  /conversations/{conversation}/title
  DELETE /conversations/{conversation}

File Explorer:
  GET    /files/browse
  GET    /files/read
  POST   /files/write
  POST   /files/create
  POST   /files/mkdir
  DELETE /files

Tasks:
  GET    /tasks
  GET    /tasks/stats        ← must be defined BEFORE /tasks/{task}
  GET    /tasks/{task}

Metrics:
  GET    /metrics

Settings:
  GET    /settings
  POST   /settings

Memory:
  GET    /memory
  POST   /memory
  DELETE /memory/{document}
  POST   /memory/search
```

**Important**: `/tasks/stats` must be registered before `/tasks/{task}` to avoid Laravel matching "stats" as a route model binding.

---

## routes/web.php
Replace with a catch-all that returns the React SPA shell for all non-API routes:

```
Route::get('/{any}', fn() => view('app'))
    ->where('any', '^(?!api|horizon).*');
```

---

## routes/channels.php
Allow subscription to conversation channels (used by Laravel Echo):

```
Broadcast::channel('conversation.{id}', fn($user, $id) => true);
```

For a single-user local app, always return `true`. If you add auth later, check that the authenticated user owns the conversation.

---

## CORS
In `config/cors.php`, allow both the Vite dev server and Laravel server:

```
'allowed_origins' => ['http://localhost:5173', 'http://localhost:8000'],
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'supports_credentials' => false,
```
