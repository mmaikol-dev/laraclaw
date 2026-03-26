<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmbeddedDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'filepath',
        'mime_type',
        'file_size',
        'chunk_count',
        'is_indexed',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_indexed' => 'boolean',
            'indexed_at' => 'datetime',
        ];
    }

    public function embeddingChunks(): HasMany
    {
        return $this->hasMany(EmbeddingChunk::class);
    }
}
