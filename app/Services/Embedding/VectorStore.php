<?php

namespace App\Services\Embedding;

use App\Models\EmbeddingChunk;

class VectorStore
{
    /**
     * @param  array<int, float|int>  $queryEmbedding
     * @return array<int, array{score: float, content: string, filename: string, filepath: string, chunk: int}>
     */
    public function search(array $queryEmbedding, int $topK = 5): array
    {
        return EmbeddingChunk::query()
            ->with('embeddedDocument')
            ->whereHas('embeddedDocument', fn ($query) => $query->where('is_indexed', true))
            ->get()
            ->map(function (EmbeddingChunk $chunk) use ($queryEmbedding): ?array {
                $score = $this->cosineSimilarity($queryEmbedding, $chunk->embedding ?? []);

                if ($score <= 0 || $chunk->embeddedDocument === null) {
                    return null;
                }

                return [
                    'score' => $score,
                    'content' => $chunk->content,
                    'filename' => $chunk->embeddedDocument->filename,
                    'filepath' => $chunk->embeddedDocument->filepath,
                    'chunk' => $chunk->chunk_index,
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->take($topK)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, float|int>  $a
     * @param  array<int, float|int>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $index => $value) {
            $left = (float) $value;
            $right = (float) $b[$index];

            $dotProduct += $left * $right;
            $magnitudeA += $left ** 2;
            $magnitudeB += $right ** 2;
        }

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
