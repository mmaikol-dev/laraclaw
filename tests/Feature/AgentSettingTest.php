<?php

namespace Tests\Feature;

use App\Models\AgentSetting;
use Database\Seeders\AgentSettingsSeeder;
use Tests\TestCase;

class AgentSettingTest extends TestCase
{
    public function test_agent_settings_defaults_include_expected_keys_and_values(): void
    {
        $defaults = collect(AgentSettingsSeeder::defaults())->keyBy('key');
        $systemPrompt = $defaults['system_prompt']['value'];

        $this->assertSame(config('agent.working_dir'), $defaults['working_dir']['value']);
        $this->assertStringContainsString((string) config('agent.working_dir'), $defaults['allowed_paths']['value']);
        $this->assertSame('int', $defaults['shell_timeout']['type']);
        $this->assertSame('true', $defaults['enable_shell']['value']);
        $this->assertSame('true', $defaults['enable_planning']['value']);
        $this->assertSame('true', $defaults['enable_affective_state']['value']);
        $this->assertSame('true', $defaults['enable_reflection']['value']);
        $this->assertSame('true', $defaults['parallel_tools']['value']);
        $this->assertSame('100', $defaults['max_iterations']['value']);
        $this->assertSame('18', $defaults['summarize_after_messages']['value']);
        $this->assertSame('0.7', $defaults['temperature']['value']);
        $this->assertSame('0.6', $defaults['fear_threshold']['value']);
        $this->assertSame('2', $defaults['sadness_threshold']['value']);
        $this->assertSame('2', $defaults['anger_cap']['value']);
        $this->assertSame('0.45', $defaults['curiosity_threshold']['value']);
        $this->assertSame('2', $defaults['boredom_threshold']['value']);
        $this->assertStringContainsString('LaraClaw', $systemPrompt);
        $this->assertStringContainsString('Do not assume the environment is restricted, read-only, or missing privileges', $systemPrompt);
        $this->assertStringContainsString('Treat every request as a problem-solving task', $systemPrompt);
        $this->assertStringContainsString('Before asking the user for more information, first check whether you already have it', $systemPrompt);
        $this->assertStringContainsString('Use memory proactively', $systemPrompt);
        $this->assertStringContainsString('Use tools proactively', $systemPrompt);
        $this->assertStringContainsString('Before giving a final answer, verify that the requested deliverable has been produced', $systemPrompt);
        $this->assertStringNotContainsString('STOP immediately', $systemPrompt);
    }

    public function test_agent_setting_cast_stored_value_handles_supported_types(): void
    {
        $this->assertTrue(AgentSetting::castStoredValue('bool', 'true'));
        $this->assertSame(45, AgentSetting::castStoredValue('int', '45'));
        $this->assertSame(['shell' => true], AgentSetting::castStoredValue('json', '{"shell":true}'));
        $this->assertSame('glm-5:cloud', AgentSetting::castStoredValue('string', 'glm-5:cloud'));
    }
}
