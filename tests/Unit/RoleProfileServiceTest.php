<?php

namespace Tests\Unit;

use App\Models\AgentRoleProfile;
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
        $profile = $service->resolveForConversation($conversation, 'Debug the deployment pipeline and queue worker.');

        $conversation->refresh();

        $this->assertSame('devops_operator', $profile?->slug);
        $this->assertSame($profile?->id, $conversation->role_profile_id);
        $this->assertSame('DevOps Operator', $conversation->identity_label);
        $this->assertDatabaseHas('agent_role_profiles', [
            'slug' => 'laravel_architect',
        ]);
    }

    public function test_it_routes_server_room_work_to_the_server_operator(): void
    {
        $service = new RoleProfileService;
        $service->ensureDefaults();

        foreach ([
            'spin up a docker container for the new site',
            'point cloudflare dns at the new host',
            'expose the app through a cloudflare tunnel',
            'self-host the docs site on the vps',
        ] as $message) {
            $conversation = Conversation::query()->create(['title' => $message]);
            $profile = $service->resolveForConversation($conversation, $message);

            $this->assertSame(
                'server_operator',
                $profile?->slug,
                "Server-room work ({$message}) must route to the Server Operator.",
            );
        }

        $this->assertDatabaseHas('agent_role_profiles', [
            'slug' => 'server_operator',
            'name' => 'Server Operator',
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

    public function test_it_routes_social_platform_work_to_the_matching_specialist(): void
    {
        $service = new RoleProfileService;
        $service->ensureDefaults();

        $cases = [
            'instagram_specialist' => 'write an instagram caption for the new reel',
            'facebook_specialist' => 'schedule a facebook post for the page',
            'youtube_specialist' => 'upload this video to youtube',
            'whatsapp_specialist' => 'send a whatsapp message to the team',
        ];

        foreach ($cases as $slug => $message) {
            $conversation = Conversation::query()->create(['title' => $message]);
            $profile = $service->resolveForConversation($conversation, $message);

            $this->assertSame($slug, $profile?->slug, "Message ({$message}) must route to {$slug}.");
        }

        $this->assertDatabaseHas('agent_role_profiles', ['slug' => 'instagram_specialist', 'name' => 'Instagram Specialist']);
        $this->assertDatabaseHas('agent_role_profiles', ['slug' => 'facebook_specialist', 'name' => 'Facebook Specialist']);
        $this->assertDatabaseHas('agent_role_profiles', ['slug' => 'youtube_specialist', 'name' => 'YouTube Specialist']);
        $this->assertDatabaseHas('agent_role_profiles', ['slug' => 'whatsapp_specialist', 'name' => 'WhatsApp Specialist']);
    }

    public function test_server_room_work_still_wins_over_whatsapp(): void
    {
        $service = new RoleProfileService;
        $service->ensureDefaults();

        foreach ([
            'restart the whatsapp container',
            'check the docker whatsapp server logs',
        ] as $message) {
            $conversation = Conversation::query()->create(['title' => $message]);
            $profile = $service->resolveForConversation($conversation, $message);

            $this->assertSame(
                'server_operator',
                $profile?->slug,
                "Infrastructure work ({$message}) must stay with the Server Operator, not the WhatsApp Specialist.",
            );
        }
    }

    public function test_social_specialist_prompts_reference_their_skill_and_the_credentials_file(): void
    {
        (new RoleProfileService)->ensureDefaults();

        foreach ([
            'instagram_specialist' => ['instagram-operations', 'INSTAGRAM_ACCESS_TOKEN'],
            'facebook_specialist' => ['facebook-operations', 'FACEBOOK_ACCESS_TOKEN'],
            'youtube_specialist' => ['youtube-operations', 'YOUTUBE_REFRESH_TOKEN'],
        ] as $slug => [$skill, $key]) {
            $profile = AgentRoleProfile::query()->where('slug', $slug)->firstOrFail();
            $this->assertStringContainsString($skill, $profile->system_prompt);
            $this->assertStringContainsString($key, $profile->system_prompt);
            $this->assertStringContainsString(config('agent.credentials_file'), $profile->system_prompt);
        }
    }
}
