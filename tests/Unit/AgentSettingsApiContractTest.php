<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\SettingsController;
use App\Http\Requests\UpdateAgentSettingsRequest;
use PHPUnit\Framework\TestCase;

class AgentSettingsApiContractTest extends TestCase
{
    public function test_update_agent_settings_request_has_expected_shape(): void
    {
        $request = new UpdateAgentSettingsRequest();

        $this->assertArrayHasKey('settings', $request->rules());
        $this->assertSame(['required', 'array'], $request->rules()['settings']);
        $this->assertArrayHasKey('settings.temperature', $request->rules());
        $this->assertArrayHasKey('settings.system_prompt', $request->rules());
    }

    public function test_settings_controller_exposes_expected_methods(): void
    {
        $controller = new SettingsController();

        $this->assertTrue(method_exists($controller, 'index'));
        $this->assertTrue(method_exists($controller, 'update'));
        $this->assertTrue(method_exists($controller, 'clearTasks'));
        $this->assertTrue(method_exists($controller, 'archiveConversations'));
    }
}
