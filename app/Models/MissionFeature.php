<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MissionFeature extends Model
{
    protected $fillable = [
        'mission_id',
        'title',
        'description',
        'milestone',
        'sort_order',
        'status',
        'attempts',
        'notes',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(MissionHandoff::class, 'mission_feature_id')->latest();
    }

    public function markAttempted(): void
    {
        $this->increment('attempts');
    }
}
