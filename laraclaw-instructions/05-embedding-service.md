# 05 — EmbeddingService & VectorStore

## What to do
Create two classes: `app/Services/Embedding/EmbeddingService.php` and `app/Services/Embedding/VectorStore.php`.

---

## EmbeddingService

Depends on: `OllamaService`, `VectorStore`

### Method: `indexFile(string $filepath): EmbeddedDocument`
- Validate the file exists
- Detect mime type with `mime_content_type()`
- Extract text: use `smalot/pdfparser` for PDFs, `file_get_contents` for everything else
- Split text into overlapping chunks — recommended chunk size ~500 chars with ~50 char overlap
- Use `EmbeddedDocument::updateOrCreate` keyed on `filepath`
- Delete the document's existing chunks before re-indexing
- Call `OllamaService::embed()` in batches of 10 chunks to avoid timeout
- Save each chunk as an `EmbeddingChunk` row with its `embedding` float array
- Mark the document `is_indexed = true` and set `indexed_at`
- Return the `EmbeddedDocument`

### Method: `search(string $query, int $topK = 5): array`
- Embed the query string using `OllamaService::embed()`
- Delegate to `VectorStore::search()` with the query vector and topK
- Return the results array

### Method: `summarize(string $filepath): string`
- If the file is not indexed yet, call `indexFile()` first
- Fetch the first 10 chunks from the document
- Join them and send to `OllamaService::chat()` with a prompt asking for a concise summary
- Return the assistant's response content string

---

## VectorStore

### Method: `search(array $queryEmbedding, int $topK = 5): array`
- Load all `EmbeddingChunk` rows that belong to indexed documents (eager load `document`)
- For each chunk, compute cosine similarity between `$queryEmbedding` and `chunk.embedding`
- Filter out chunks with similarity ≤ 0
- Sort descending by score, take top K
- Return an array of results, each with: `score`, `content`, `filename`, `filepath`, `chunk` (index)

### Cosine similarity implementation
Implement a private `cosineSimilarity(array $a, array $b): float` method.
- Return 0.0 if either array is empty or lengths differ
- Formula: dot product / (magnitude of A × magnitude of B)
