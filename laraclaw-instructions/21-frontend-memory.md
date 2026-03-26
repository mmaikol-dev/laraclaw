# 21 — Frontend: Memory / Documents Page

File: `resources/js/pages/Memory/MemoryPage.tsx`

---

## What this page does
Manages the vector store — lets the user index files so the agent can semantically search them using qwen3-embedding. Also provides a manual search interface to test what the agent would find.

---

## Data source
- Fetch indexed documents from `GET /api/v1/memory`
- Index a new file with `POST /api/v1/memory` body `{ path: string }`
- Delete a document with `DELETE /api/v1/memory/{id}`
- Search with `POST /api/v1/memory/search` body `{ query, top_k }`

---

## Layout
Two columns:
- **Left** (~40%): Indexed documents list + add document form
- **Right** (~60%): Semantic search panel

---

## Left — Indexed documents

### Add document form
- A text input for the file path (absolute path on the Linux machine)
- "Index file" button — POST to `/api/v1/memory`
- On success: show toast "Queued for indexing", refresh the list after a few seconds
- Note below the input: "Supported types: PDF, TXT, MD, CSV, and other plain text files"

### Document list
Each card shows:
- Filename (bold)
- Full filepath (muted, truncated)
- Chunk count badge
- `is_indexed` status — green "Indexed" or amber "Indexing..."
- `indexed_at` relative time
- File size (human readable)
- Delete button (trash icon) — confirm before deleting

Empty state: "No documents indexed yet. Add a file path above to get started."

---

## Right — Semantic search

### Search input
- Text input for the search query
- Number input for `top_k` (default 5, range 1–20)
- "Search" button

### Results
For each result returned from `POST /api/v1/memory/search`:
- Similarity score as a percentage bar (score is 0.0–1.0, render as a visual bar)
- Filename and chunk index
- The matched text chunk content in a `<pre>` block

Empty result: "No matching chunks found. Try a different query or index more files."

---

## Stats bar (top of page)
A small summary row:
- Total documents indexed
- Total chunks stored
- Fetch by counting from the document list response

---

## Polling for indexing status
After queuing a file, poll `GET /api/v1/memory` every 3 seconds until the new document's `is_indexed` becomes `true`, then stop polling and show the final chunk count.
