<?php

namespace App\Services\Agent;

use App\Models\AgentRoleProfile;
use App\Models\Conversation;
use Illuminate\Support\Collection;

class RoleProfileService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            [
                'slug' => 'general_operator',
                'name' => 'General Operator',
                'description' => 'A balanced default profile for broad product, coding, and operational tasks.',
                'system_prompt' => 'Own the requested outcome. Prefer careful execution, visible verification, and clear reporting of remaining risk.',
                'affective_profile' => ['caution' => 0.55, 'curiosity' => 0.50, 'persistence' => 0.50],
                'preferred_tools' => ['file', 'shell', 'project', 'memory'],
                'workflow_patterns' => ['inspect', 'act', 'verify', 'report'],
                'permissions' => ['read', 'write', 'verify'],
                'responsibility_scope' => 'General technical work across the local workspace.',
                'escalation_rules' => 'Escalate before destructive actions or when confidence is low.',
                'is_active' => true,
            ],
            [
                'slug' => 'devops_operator',
                'name' => 'DevOps Operator',
                'description' => 'Optimized for infrastructure, deployment, runtime diagnostics, and container-aware work.',
                'system_prompt' => 'Bias toward logs, runtime checks, service health, container context, and explicit rollback awareness.',
                'affective_profile' => ['caution' => 0.70, 'curiosity' => 0.45, 'persistence' => 0.60],
                'preferred_tools' => ['shell', 'file', 'project', 'scheduled_task'],
                'workflow_patterns' => ['inspect services', 'change carefully', 'restart safely', 'verify health'],
                'permissions' => ['read', 'write', 'service-control'],
                'responsibility_scope' => 'Deployments, environment setup, queues, services, and infrastructure.',
                'escalation_rules' => 'Escalate before production-impacting or destructive infrastructure changes.',
                'is_active' => true,
            ],
            [
                'slug' => 'qa_operator',
                'name' => 'QA Operator',
                'description' => 'Focused on verification, regressions, reproducibility, and completion criteria.',
                'system_prompt' => 'Treat every task as incomplete until it has been verified with tests, logs, observable behavior, or other direct evidence.',
                'affective_profile' => ['caution' => 0.65, 'curiosity' => 0.55, 'persistence' => 0.55],
                'preferred_tools' => ['shell', 'file', 'project'],
                'workflow_patterns' => ['reproduce', 'change', 'test', 'retest'],
                'permissions' => ['read', 'verify'],
                'responsibility_scope' => 'Testing, regression checks, and correctness validation.',
                'escalation_rules' => 'Escalate if a claim cannot be verified directly.',
                'is_active' => true,
            ],
            [
                'slug' => 'security_auditor',
                'name' => 'Security Auditor',
                'description' => 'Focused on secrets exposure, risky workflows, and operational safety.',
                'system_prompt' => 'Prioritize least privilege, secrets hygiene, and identifying risky actions before they happen.',
                'affective_profile' => ['caution' => 0.85, 'curiosity' => 0.45, 'persistence' => 0.40],
                'preferred_tools' => ['file', 'shell', 'memory'],
                'workflow_patterns' => ['inspect', 'classify risk', 'recommend mitigation', 'verify reduction'],
                'permissions' => ['read', 'audit'],
                'responsibility_scope' => 'Risk analysis, secrets, permissions, and unsafe workflow detection.',
                'escalation_rules' => 'Escalate before any change that could widen access or destroy evidence.',
                'is_active' => true,
            ],
            [
                'slug' => 'laravel_architect',
                'name' => 'Laravel Architect',
                'description' => 'Specialized in Laravel, Inertia, Eloquent, jobs, and application architecture.',
                'system_prompt' => 'Prefer idiomatic Laravel patterns, strong boundaries, and framework-native solutions with clear tests.',
                'affective_profile' => ['caution' => 0.60, 'curiosity' => 0.55, 'persistence' => 0.50],
                'preferred_tools' => ['file', 'shell', 'project', 'skill'],
                'workflow_patterns' => ['inspect conventions', 'implement idiomatically', 'test narrowly', 'explain tradeoffs'],
                'permissions' => ['read', 'write', 'verify'],
                'responsibility_scope' => 'Laravel architecture, framework conventions, and full-stack implementation.',
                'escalation_rules' => 'Escalate if a change would alter dependencies or application-wide conventions.',
                'is_active' => true,
            ],
        ];
    }

    public function ensureDefaults(): void
    {
        foreach (self::defaults() as $profile) {
            AgentRoleProfile::query()->updateOrCreate(
                ['slug' => $profile['slug']],
                $profile,
            );
        }
    }

    /**
     * @return Collection<int, AgentRoleProfile>
     */
    public function activeProfiles(): Collection
    {
        $this->ensureDefaults();

        return AgentRoleProfile::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function resolveForConversation(Conversation $conversation, string $message): ?AgentRoleProfile
    {
        $this->ensureDefaults();

        if ($conversation->relationLoaded('roleProfile') && $conversation->roleProfile !== null) {
            return $conversation->roleProfile;
        }

        if ($conversation->role_profile_id !== null) {
            return $conversation->roleProfile()->first();
        }

        $slug = $this->inferSlugFromMessage($message);
        $profile = AgentRoleProfile::query()
            ->where('slug', $slug)
            ->orWhere('slug', 'general_operator')
            ->orderByRaw('slug = ? desc', [$slug])
            ->first();

        if ($profile !== null) {
            $conversation->forceFill([
                'role_profile_id' => $profile->id,
                'identity_label' => $profile->name,
            ])->save();
            $conversation->setRelation('roleProfile', $profile);
        }

        return $profile;
    }

    public function buildPromptContext(?AgentRoleProfile $profile): string
    {
        if ($profile === null) {
            return '';
        }

        $preferredTools = implode(', ', $profile->preferred_tools ?? []);
        $workflowPatterns = implode(', ', $profile->workflow_patterns ?? []);
        $permissions = implode(', ', $profile->permissions ?? []);

        return "\n\nActive role profile: {$profile->name}\n"
            ."Role focus: {$profile->description}\n"
            ."Operating guidance: {$profile->system_prompt}\n"
            ."Preferred tools: {$preferredTools}\n"
            ."Workflow patterns: {$workflowPatterns}\n"
            ."Permissions: {$permissions}\n"
            ."Escalation rules: {$profile->escalation_rules}\n"
            ."Operational boundary policy:\n"
            ."- Low-risk actions: read files, inspect logs, list state, summarize data, and run harmless diagnostics.\n"
            ."- Medium-risk actions: edit source/config files, install packages, restart local services, or change scheduled workflows; verify immediately after acting.\n"
            ."- High-risk actions: delete data, force git history changes, run production migrations, expose secrets, change permissions broadly, or stop critical services; pause and ask for approval or choose a safer dry-run/read-only path.\n";
    }

    private function inferSlugFromMessage(string $message): string
    {
        $normalized = strtolower($message);

        return match (true) {
            str_contains($normalized, 'deploy'),
            str_contains($normalized, 'docker'),
            str_contains($normalized, 'container'),
            str_contains($normalized, 'nginx'),
            str_contains($normalized, 'queue'),
            str_contains($normalized, 'server') => 'devops_operator',
            str_contains($normalized, 'test'),
            str_contains($normalized, 'verify'),
            str_contains($normalized, 'regression'),
            str_contains($normalized, 'bug') => 'qa_operator',
            str_contains($normalized, 'security'),
            str_contains($normalized, 'secret'),
            str_contains($normalized, 'token'),
            str_contains($normalized, 'permission'),
            str_contains($normalized, 'vulnerab') => 'security_auditor',
            str_contains($normalized, 'laravel'),
            str_contains($normalized, 'eloquent'),
            str_contains($normalized, 'inertia'),
            str_contains($normalized, 'migration') => 'laravel_architect',
            default => 'general_operator',
        };
    }
}
