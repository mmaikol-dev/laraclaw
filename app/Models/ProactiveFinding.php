<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProactiveFinding extends Model
{
    protected $fillable = [
        'category',
        'severity',
        'status',
        'title',
        'summary',
        'details',
        'fingerprint',
        'source',
        'meta',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
