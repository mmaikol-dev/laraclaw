<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillScript extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'skill_id',
        'filename',
        'description',
        'content',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
