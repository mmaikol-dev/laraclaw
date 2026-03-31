<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trigger extends Model
{
    protected $fillable = [
        'name', 'description', 'type', 'config', 'prompt',
        'is_active', 'last_triggered_at', 'last_value', 'webhook_secret',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'config' => 'array',
        'last_triggered_at' => 'datetime',
    ];
}
