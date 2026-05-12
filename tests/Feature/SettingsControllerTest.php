<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SettingsController;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    public function test_settings_endpoint_includes_seed_defaults_even_when_not_persisted_yet(): void
    {
        $controller = new class extends SettingsController
        {
            protected function storedSettings(): array
            {
                return [
                    'working_dir' => '/custom/workspace',
                    'enable_shell' => false,
                ];
            }
        };

        $payload = $controller->index()->getData(true);

        $this->assertSame('/custom/workspace', $payload['data']['working_dir']);
        $this->assertFalse($payload['data']['enable_shell']);
        $this->assertTrue($payload['data']['enable_planning']);
        $this->assertTrue($payload['data']['enable_affective_state']);
        $this->assertTrue($payload['data']['enable_reflection']);
        $this->assertFalse($payload['data']['parallel_tools']);
        $this->assertSame(24, $payload['data']['max_iterations']);
        $this->assertSame(18, $payload['data']['summarize_after_messages']);
        $this->assertSame(1, $payload['data']['max_tool_retries']);
        $this->assertSame('0.6', $payload['data']['fear_threshold']);
        $this->assertSame(2, $payload['data']['sadness_threshold']);
        $this->assertSame(2, $payload['data']['anger_cap']);
        $this->assertSame('0.45', $payload['data']['curiosity_threshold']);
        $this->assertSame(2, $payload['data']['boredom_threshold']);
    }
}
