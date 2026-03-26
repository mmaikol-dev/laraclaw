<?php

namespace Database\Factories;

use App\Models\EmbeddedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmbeddedDocument>
 */
class EmbeddedDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => fake()->word().'.txt',
            'filepath' => '/tmp/laraclaw/'.fake()->word().'.txt',
            'mime_type' => 'text/plain',
            'file_size' => fake()->numberBetween(128, 50000),
            'chunk_count' => fake()->numberBetween(1, 20),
            'is_indexed' => false,
            'indexed_at' => null,
        ];
    }
}
