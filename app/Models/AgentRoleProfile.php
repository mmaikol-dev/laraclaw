<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRoleProfile extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'system_prompt',
        'affective_profile',
        'preferred_tools',
        'workflow_patterns',
        'permissions',
        'responsibility_scope',
        'escalation_rules',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'affective_profile' => 'array',
            'preferred_tools' => 'array',
            'workflow_patterns' => 'array',
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'role_profile_id');
    }
}
