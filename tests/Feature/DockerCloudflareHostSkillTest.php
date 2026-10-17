<?php

namespace Tests\Feature;

use App\Models\AgentRoleProfile;
use App\Models\Skill;
use App\Services\Agent\RoleProfileService;
use Database\Seeders\SkillsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DockerCloudflareHostSkillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_skills_seeder_registers_the_docker_cloudflare_host_skill(): void
    {
        $this->seed(SkillsSeeder::class);

        $this->assertDatabaseHas('skills', [
            'name' => 'docker-cloudflare-host',
            'category' => 'system',
            'created_by' => 'user',
            'is_active' => true,
        ]);

        $skill = Skill::query()->where('name', 'docker-cloudflare-host')->firstOrFail();

        $this->assertStringContainsString('759073dd-6847-4d35-9325-08bf1addd5e3', $skill->instructions);
        $this->assertStringContainsString('wazuri-api', $skill->instructions);
        $this->assertStringContainsString('docker compose down -v', $skill->instructions);
        $this->assertStringContainsString('sitebase.co.ke', $skill->instructions);
    }

    public function test_the_skills_seeder_is_idempotent(): void
    {
        $this->seed(SkillsSeeder::class);
        $this->seed(SkillsSeeder::class);

        $this->assertSame(1, Skill::query()->where('name', 'docker-cloudflare-host')->count());
    }

    public function test_the_server_operator_prompt_carries_the_host_guardrails(): void
    {
        (new RoleProfileService)->ensureDefaults();

        $profile = AgentRoleProfile::query()->where('slug', 'server_operator')->firstOrFail();

        $this->assertStringContainsString('docker-cloudflare-host', $profile->system_prompt);
        $this->assertStringContainsString('docker compose down -v', $profile->system_prompt);
        $this->assertStringContainsString('ASK FIRST', $profile->system_prompt);
        $this->assertStringContainsString('Cloudflare Access', $profile->system_prompt);
    }
}
