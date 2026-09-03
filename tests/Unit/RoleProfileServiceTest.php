<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Services\Agent\RoleProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_role_profiles_and_assigns_a_contextual_profile(): void
    {
        $conversation = Conversation::query()->create([
            'title' => 'Deployment issue',
        ]);

        $service = new RoleProfileService;
        $profile = $service->resolveForConversation($conversation, 'Debug the Docker deployment and queue worker.');

        $conversation->refresh();

        $this->assertSame('devops_operator', $profile?->slug);
        $this->assertSame($profile?->id, $conversation->role_profile_id);
        $this->assertSame('DevOps Operator', $conversation->identity_label);
        $this->assertDatabaseHas('agent_role_profiles', [
            'slug' => 'laravel_architect',
        ]);
    }

    public function test_prompt_context_includes_operational_boundaries(): void
    {
        $service = new RoleProfileService;
        $service->ensureDefaults();

        $profile = $service->activeProfiles()->firstWhere('slug', 'security_auditor');
        $context = $service->buildPromptContext($profile);

        $this->assertStringContainsString('Active role profile: Security Auditor', $context);
        $this->assertStringContainsString('Escalation rules:', $context);
        $this->assertStringContainsString('Preferred tools:', $context);
    }
}
