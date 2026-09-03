<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentReport extends Model
{
    protected $fillable = [
        'report_date', 'type', 'title', 'content', 'conversation_id', 'meta',
    ];

    protected $casts = [
        'report_date' => 'date',
        'meta' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
