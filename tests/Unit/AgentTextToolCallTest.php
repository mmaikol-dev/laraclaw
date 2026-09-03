<?php

namespace Tests\Unit;

use App\Services\Agent\AffectiveStateEngine;
use App\Services\Agent\AgentIdentityService;
use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use App\Services\Agent\GoalOwnershipService;
use App\Services\Agent\OllamaService;
use App\Services\Agent\ProactiveMonitoringService;
use App\Services\Agent\RoleProfileService;
use App\Services\Agent\ToolRegistry;
use App\Services\Tools\WebTool;
use Mockery;
use Tests\TestCase;

class AgentTextToolCallTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function serviceWithWebTool(): AgentService
    {
        $registry = Mockery::mock(ToolRegistry::class);
        $registry->shouldReceive('all')->andReturn(['web' => new WebTool]);

        return new AgentService(
            Mockery::mock(OllamaService::class),
            $registry,
            Mockery::mock(AgentRunState::class),
            Mockery::mock(AffectiveStateEngine::class),
            Mockery::mock(GoalOwnershipService::class),
            Mockery::mock(RoleProfileService::class),
            Mockery::mock(ProactiveMonitoringService::class),
            Mockery::mock(AgentIdentityService::class),
        );
    }

    public function test_it_parses_loose_text_tool_call_without_json_quoting(): void
    {
        $service = $this->serviceWithWebTool();
        $method = new \ReflectionMethod($service, 'extractTextToolCalls');
        $method->setAccessible(true);

        $content = 'call:web{action:search,query:high demand freelance services for AI agents 2025 digital product ideas}';

        [$toolCalls, $remaining] = $method->invoke($service, $content);

        $this->assertCount(1, $toolCalls);
        $this->assertSame('web', $toolCalls[0]['function']['name']);
        $this->assertSame('search', $toolCalls[0]['function']['arguments']['action']);
        $this->assertSame('high demand freelance services for AI agents 2025 digital product ideas', $toolCalls[0]['function']['arguments']['query']);
        $this->assertSame('', trim($remaining));
    }

    public function test_it_parses_quoted_json_tool_call(): void
    {
        $service = $this->serviceWithWebTool();
        $method = new \ReflectionMethod($service, 'extractTextToolCalls');
        $method->setAccessible(true);

        $content = 'call:web{"action":"search","query":"top AI tools","max_results":3}';

        [$toolCalls, $remaining] = $method->invoke($service, $content);

        $this->assertCount(1, $toolCalls);
        $this->assertSame('web', $toolCalls[0]['function']['name']);
        $this->assertSame(['action' => 'search', 'query' => 'top AI tools', 'max_results' => 3], $toolCalls[0]['function']['arguments']);
        $this->assertSame('', trim($remaining));
    }

    public function test_it_preserves_surrounding_text_and_keeps_plain_text_untouched(): void
    {
        $service = $this->serviceWithWebTool();
        $method = new \ReflectionMethod($service, 'extractTextToolCalls');
        $method->setAccessible(true);

        [$toolCalls, $remaining] = $method->invoke($service, "Let me check that.\ncall:web{action:search,query:top AI tools}");

        $this->assertCount(1, $toolCalls);
        $this->assertSame("Let me check that.\n", $remaining);

        [$emptyCalls, $unchanged] = $method->invoke($service, 'Just a normal answer here.');
        $this->assertSame([], $emptyCalls);
        $this->assertSame('Just a normal answer here.', $unchanged);
    }

    public function test_it_parses_unquoted_action_and_trailing_brace_cleanly(): void
    {
        $service = $this->serviceWithWebTool();
        $method = new \ReflectionMethod($service, 'extractTextToolCalls');
        $method->setAccessible(true);

        $content = 'call:web{action:fetch,url:https://example.com}';

        [$toolCalls, $remaining] = $method->invoke($service, $content);

        $this->assertCount(1, $toolCalls);
        $this->assertSame('fetch', $toolCalls[0]['function']['arguments']['action']);
        $this->assertSame('https://example.com', $toolCalls[0]['function']['arguments']['url']);
    }
}
