<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillVersion extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'skill_id',
        'version',
        'instructions',
        'description',
        'changed_by',
        'change_note',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'version' => 'integer',
    ];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
