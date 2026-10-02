<?php

namespace Tests\Unit;

use App\Services\Agent\AgentService;
use App\Services\Agent\ToolRegistry;
use App\Services\TaskEngine\TaskEngine;
use Tests\TestCase;

/**
 * The agent tool layer, the task engine and the agent runtime are mutually
 * dependent. Registering them as eager singletons therefore risks a resolution
 * cycle (ToolRegistry -> TaskEngine -> AgentService -> ToolRegistry -> ...),
 * which recurses until the container exhausts memory and takes the request
 * down with it. These tests pin the wiring so the cycle cannot be reintroduced.
 */
class AgentContainerResolutionTest extends TestCase
{
    public function test_tool_registry_resolves_without_recursing(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertArrayHasKey('shell', $registry->all());
        $this->assertArrayHasKey('task_engine', $registry->all());
    }

    public function test_task_engine_resolves_without_recursing(): void
    {
        $this->assertInstanceOf(TaskEngine::class, $this->app->make(TaskEngine::class));
    }

    public function test_agent_service_resolves_without_recursing(): void
    {
        $this->assertInstanceOf(AgentService::class, $this->app->make(AgentService::class));
    }

    public function test_task_engine_is_the_same_singleton_regardless_of_entry_point(): void
    {
        $viaRegistryTool = $this->app->make(ToolRegistry::class)->get('task_engine');
        $viaContainer = $this->app->make(TaskEngine::class);

        $this->assertNotNull($viaRegistryTool);
        $this->assertSame($viaContainer, $this->invokeEngine($viaRegistryTool));
    }

    private function invokeEngine(object $tool): object
    {
        $method = new \ReflectionMethod($tool, 'engine');

        return $method->invoke($tool);
    }
}
