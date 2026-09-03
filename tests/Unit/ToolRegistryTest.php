<?php

namespace Tests\Unit;

use App\Services\Agent\ToolRegistry;
use App\Services\Tools\BaseTool;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    public function test_tool_registry_formats_enabled_tools_for_ollama(): void
    {
        $registry = new ToolRegistry();
        $registry->register(new class extends BaseTool
        {
            public function getName(): string
            {
                return 'example';
            }

            public function getDescription(): string
            {
                return 'Example tool.';
            }

            public function getParameters(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $arguments): string
            {
                return 'ok';
            }
        });

        $this->assertSame([
            [
                'type' => 'function',
                'function' => [
                    'name' => 'example',
                    'description' => 'Example tool.',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ],
        ], $registry->toOllamaTools());
    }

    public function test_tool_registry_returns_error_for_unknown_tools(): void
    {
        $registry = new ToolRegistry();

        $result = $registry->execute('missing', []);

        $this->assertSame('', $result['output']);
        $this->assertSame('Tool [missing] is not registered.', $result['error']);
        $this->assertSame(0, $result['duration_ms']);
    }

    public function test_base_tool_truncate_limits_output_lines(): void
    {
        $tool = new class extends BaseTool
        {
            public function getName(): string
            {
                return 'truncate';
            }

            public function getDescription(): string
            {
                return 'Truncate tool.';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function execute(array $arguments): string
            {
                return '';
            }
        };

        $output = $tool->truncate("one\ntwo\nthree", 2);

        $this->assertStringContainsString('one', $output);
        $this->assertStringContainsString('two', $output);
        $this->assertStringContainsString('Output truncated', $output);
    }
}
