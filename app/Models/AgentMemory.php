<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AgentMemory extends Model
{
    protected $fillable = [
        'key', 'value', 'category', 'tags', 'expires_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'expires_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
