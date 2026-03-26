<?php

namespace Database\Factories;

use App\Models\EmbeddedDocument;
use App\Models\EmbeddingChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbeddingChunk>
 */
class EmbeddingChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'embedded_document_id' => EmbeddedDocument::factory(),
            'chunk_index' => fake()->numberBetween(0, 50),
            'content' => fake()->paragraph(),
            'embedding' => [0.01, 0.02, 0.03],
        ];
    }
}
