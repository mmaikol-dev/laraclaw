# 10 — DocumentTool

## What to do
Create `app/Services/Tools/DocumentTool.php` extending `BaseTool`.

Depends on: `EmbeddingService`

---

## Tool name
`document`

## Actions
| Action | What it does |
|---|---|
| `read` | Return full text content of a PDF or text file |
| `summarize` | Return an AI-generated summary using EmbeddingService |
| `index` | Embed a file into the vector store |
| `search` | Semantic search across all indexed documents |

## Parameters schema
Required: `action`
Optional: `path` (for read/summarize/index), `query` (for search), `top_k` (int, default 5)

---

## read action
- Check that the file exists
- For PDFs: use `smalot/pdfparser` to extract text
- For everything else: use `file_get_contents`
- Prepend `=== {path} ===` header
- Call `$this->truncate()` before returning

## summarize action
- Delegate entirely to `EmbeddingService::summarize($path)`
- That method handles indexing if not already done

## index action
- Call `EmbeddingService::indexFile($path)`
- Return a confirmation string: `Indexed '{filename}' — {chunk_count} chunks embedded.`

## search action
- Validate the query is not empty
- Call `EmbeddingService::search($query, $topK)`
- Format each result as:
  ```
  1. [filename] (score: 0.923)
  The relevant text chunk content...
  ```
- Return "No relevant documents found" if empty
