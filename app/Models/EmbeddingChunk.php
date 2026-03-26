<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmbeddingChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'embedded_document_id',
        'chunk_index',
        'content',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }

    public function embeddedDocument(): BelongsTo
    {
        return $this->belongsTo(EmbeddedDocument::class);
    }
}
