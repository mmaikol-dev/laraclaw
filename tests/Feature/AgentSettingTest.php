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

        $this->assertSame('/tmp/laraclaw', $defaults['working_dir']['value']);
        $this->assertStringContainsString('/tmp/laraclaw', $defaults['allowed_paths']['value']);
        $this->assertSame('int', $defaults['shell_timeout']['type']);
        $this->assertSame('true', $defaults['enable_shell']['value']);
        $this->assertSame('0.7', $defaults['temperature']['value']);
        $this->assertStringContainsString('LaraClaw', $defaults['system_prompt']['value']);
    }

    public function test_agent_setting_cast_stored_value_handles_supported_types(): void
    {
        $this->assertTrue(AgentSetting::castStoredValue('bool', 'true'));
        $this->assertSame(45, AgentSetting::castStoredValue('int', '45'));
        $this->assertSame(['shell' => true], AgentSetting::castStoredValue('json', '{"shell":true}'));
        $this->assertSame('glm-5:cloud', AgentSetting::castStoredValue('string', 'glm-5:cloud'));
    }
}
