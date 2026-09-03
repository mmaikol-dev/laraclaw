<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionHandoff extends Model
{
    protected $fillable = [
        'mission_id',
        'mission_feature_id',
        'role',
        'summary',
        'completed_work',
        'undone_work',
        'execution_log',
        'discovered_issues',
        'procedure_adhered',
    ];

    protected $casts = [
        'execution_log' => 'array',
        'procedure_adhered' => 'boolean',
    ];

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(MissionFeature::class, 'mission_feature_id');
    }
}
