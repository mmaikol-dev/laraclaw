<?php

namespace App\Services\Embedding;

use App\Models\EmbeddedDocument;
use App\Services\Agent\OllamaService;
use RuntimeException;

class EmbeddingService
{
    public function __construct(
        protected OllamaService $ollama,
        protected VectorStore $vectorStore,
    ) {}

    public function indexFile(string $filepath): EmbeddedDocument
    {
        if (! is_file($filepath)) {
            throw new RuntimeException("File not found: {$filepath}");
        }

        $content = $this->extractText($filepath);
        $chunks = $this->chunkText($content);

        $document = EmbeddedDocument::query()->updateOrCreate(
            ['filepath' => $filepath],
            [
                'filename' => basename($filepath),
                'mime_type' => mime_content_type($filepath) ?: null,
                'file_size' => filesize($filepath) ?: 0,
                'chunk_count' => count($chunks),
                'is_indexed' => false,
                'indexed_at' => null,
            ],
        );

        $document->embeddingChunks()->delete();

        foreach (array_chunk($chunks, 10, true) as $chunkBatch) {
            $embeddings = $this->ollama->embed(array_values($chunkBatch));

            foreach (array_values($chunkBatch) as $index => $chunk) {
                $document->embeddingChunks()->create([
                    'chunk_index' => array_keys($chunkBatch)[$index],
                    'content' => $chunk,
                    'embedding' => $embeddings[$index] ?? [],
                ]);
            }
        }

        $document->forceFill([
            'chunk_count' => count($chunks),
            'is_indexed' => true,
            'indexed_at' => now(),
        ])->save();

        return $document->refresh();
    }

    /**
     * @return array<int, array{score: float, content: string, filename: string, filepath: string, chunk: int}>
     */
    public function search(string $query, int $topK = 5): array
    {
        $embeddings = $this->ollama->embed($query);

        return $this->vectorStore->search($embeddings[0] ?? [], $topK);
    }

    public function summarize(string $filepath): string
    {
        $document = EmbeddedDocument::query()->where('filepath', $filepath)->first();

        if ($document === null || ! $document->is_indexed) {
            $document = $this->indexFile($filepath);
        }

        $context = $document->embeddingChunks()
            ->orderBy('chunk_index')
            ->limit(10)
            ->pluck('content')
            ->implode("\n\n");

        $response = $this->ollama->chat([
            [
                'role' => 'system',
                'content' => 'Summarize documents clearly and concisely.',
            ],
            [
                'role' => 'user',
                'content' => "Summarize this document:\n\n{$context}",
            ],
        ], [], []);

        return (string) data_get($response, 'message.content', 'No summary available.');
    }

    private function extractText(string $filepath): string
    {
        $mimeType = mime_content_type($filepath) ?: '';

        if (str_contains($mimeType, 'pdf')) {
            if (! class_exists(\Smalot\PdfParser\Parser::class)) {
                throw new RuntimeException('PDF support is not installed. Add smalot/pdfparser before indexing PDF files.');
            }

            $parser = new \Smalot\PdfParser\Parser();

            return $parser->parseFile($filepath)->getText();
        }

        $content = file_get_contents($filepath);

        if ($content === false) {
            throw new RuntimeException("Unable to read file: {$filepath}");
        }

        return $content;
    }

    /**
     * @return array<int, string>
     */
    private function chunkText(string $text, int $chunkSize = 500, int $overlap = 50): array
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text) ?? $text);

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $chunks[] = substr($text, $offset, $chunkSize);
            $offset += max(1, $chunkSize - $overlap);
        }

        return $chunks;
    }
}
