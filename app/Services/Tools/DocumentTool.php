<?php

namespace App\Services\Tools;

use App\Services\Embedding\EmbeddingService;
use RuntimeException;

class DocumentTool extends BaseTool
{
    public function __construct(
        protected EmbeddingService $embeddingService,
    ) {}

    public function getName(): string
    {
        return 'document';
    }

    public function getDescription(): string
    {
        return 'Read, summarize, index, and semantically search local documents that LaraClaw has access to.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string'],
                'path' => ['type' => 'string'],
                'query' => ['type' => 'string'],
                'top_k' => ['type' => 'integer'],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'read' => $this->read((string) ($arguments['path'] ?? '')),
            'summarize' => $this->embeddingService->summarize((string) ($arguments['path'] ?? '')),
            'index' => $this->index((string) ($arguments['path'] ?? '')),
            'search' => $this->search((string) ($arguments['query'] ?? ''), (int) ($arguments['top_k'] ?? 5)),
            default => throw new RuntimeException('Unsupported document action.'),
        };
    }

    private function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('Document not found.');
        }

        $mimeType = mime_content_type($path) ?: '';

        if (str_contains($mimeType, 'pdf')) {
            if (! class_exists(\Smalot\PdfParser\Parser::class)) {
                throw new RuntimeException('PDF support is not installed.');
            }

            $parser = new \Smalot\PdfParser\Parser();
            $content = $parser->parseFile($path)->getText();
        } else {
            $content = file_get_contents($path);
        }

        if ($content === false) {
            throw new RuntimeException('Unable to read the document.');
        }

        return $this->truncate("=== {$path} ===\n{$content}");
    }

    private function index(string $path): string
    {
        $document = $this->embeddingService->indexFile($path);

        return "Indexed '{$document->filename}' - {$document->chunk_count} chunks embedded.";
    }

    private function search(string $query, int $topK): string
    {
        if (trim($query) === '') {
            throw new RuntimeException('A search query is required.');
        }

        $results = $this->embeddingService->search($query, $topK);

        if ($results === []) {
            return 'No relevant documents found.';
        }

        return collect($results)->values()->map(function (array $result, int $index): string {
            return sprintf(
                "%d. [%s] (score: %.3f)\n%s",
                $index + 1,
                $result['filename'],
                $result['score'],
                $result['content'],
            );
        })->implode("\n\n");
    }
}
