<?php

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'category',
        'instructions',
        'is_active',
        'created_by',
        'usage_count',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    public const CATEGORIES = [
        'coding',
        'research',
        'writing',
        'system',
        'data',
        'analysis',
        'communication',
        'general',
    ];

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }
}
