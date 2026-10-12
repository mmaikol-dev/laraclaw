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

    public function test_the_employee_hands_off_when_the_task_changes_specialty(): void
    {
        $conversation = Conversation::query()->create(['title' => 'Mixed work']);
        $service = new RoleProfileService;

        $first = $service->resolveForConversation($conversation, 'Check this repo for security vulnerabilities');

        $this->assertSame('security_auditor', $first?->slug);

        $second = $service->resolveForConversation($conversation->fresh(), 'now help me write a Laravel migration for orders');

        $this->assertSame('laravel_architect', $second?->slug, 'A new specialty must hand off to the matching employee.');
        $this->assertSame('laravel_architect', $conversation->fresh()->roleProfile?->slug);
    }

    public function test_a_low_signal_follow_up_keeps_the_current_employee(): void
    {
        $conversation = Conversation::query()->create(['title' => 'Security review']);
        $service = new RoleProfileService;

        $service->resolveForConversation($conversation, 'Check this repo for security vulnerabilities');

        foreach (['yes, do it', 'continue', 'looks good'] as $reply) {
            $profile = $service->resolveForConversation($conversation->fresh(), $reply);

            $this->assertSame(
                'security_auditor',
                $profile?->slug,
                "A vague follow-up ({$reply}) must not swap the employee mid-task.",
            );
        }
    }

    public function test_a_new_conversation_with_no_signal_starts_on_the_general_operator(): void
    {
        $conversation = Conversation::query()->create(['title' => 'Idle']);
        $service = new RoleProfileService;

        $profile = $service->resolveForConversation($conversation, 'hello');

        $this->assertSame('general_operator', $profile?->slug);
    }

    public function test_the_label_tracks_the_employee_of_the_latest_run(): void
    {
        $conversation = Conversation::query()->create(['title' => 'Handoff']);
        $service = new RoleProfileService;

        $service->resolveForConversation($conversation, 'debug the failing regression tests');
        $this->assertSame('QA Operator', $conversation->fresh()->identity_label);

        $service->resolveForConversation($conversation->fresh(), 'write a laravel migration for the orders table');
        $this->assertSame('Laravel Architect', $conversation->fresh()->identity_label);
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
