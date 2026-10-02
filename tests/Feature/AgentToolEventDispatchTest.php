<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use App\Services\Agent\AffectiveStateEngine;
use App\Services\Agent\AgentIdentityService;
use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use App\Services\Agent\GoalOwnershipService;
use App\Services\Agent\OllamaService;
use App\Services\Agent\ProactiveMonitoringService;
use App\Services\Agent\RoleProfileService;
use App\Services\Agent\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AgentToolEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_tool_execution_events_are_persisted_when_run_inside_a_task(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create();
        Task::factory()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
        ]);
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Calling a tool.',
        ]);

        $registry = Mockery::mock(ToolRegistry::class);
        $registry->shouldReceive('execute')->andReturn([
            'output' => 'tool output',
            'error' => null,
            'duration_ms' => 12,
        ]);

        $affective = Mockery::mock(AffectiveStateEngine::class);
        $affective->shouldReceive('shouldPauseForSafety')->andReturn(false);
        $affective->shouldReceive('recordToolResult');

        $runState = Mockery::mock(AgentRunState::class);
        $runState->shouldReceive('recordTool');

        $service = new AgentService(
            Mockery::mock(OllamaService::class),
            $registry,
            $runState,
            $affective,
            Mockery::mock(GoalOwnershipService::class),
            Mockery::mock(RoleProfileService::class),
            Mockery::mock(ProactiveMonitoringService::class),
            Mockery::mock(AgentIdentityService::class),
        );

        $method = new \ReflectionMethod($service, 'executeSingleTool');
        $method->setAccessible(true);
        $method->invoke(
            $service,
            [
                'function' => [
                    'name' => 'web',
                    'arguments' => ['action' => 'search', 'query' => 'test'],
                ],
            ],
            $conversation,
            $assistantMessage,
            'conversation.'.$conversation->id,
            null,
        );

        $this->assertDatabaseHas('events', ['event_type' => 'tool.started', 'entity_type' => 'task']);
        $this->assertDatabaseHas('events', ['event_type' => 'tool.completed', 'entity_type' => 'task']);
        $this->assertSame(2, Event::query()->whereNotNull('entity_id')->count());
    }

    public function test_tool_execution_events_are_skipped_outside_task_context(): void
    {
        $conversation = Conversation::factory()->create();
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Calling a tool.',
        ]);

        $registry = Mockery::mock(ToolRegistry::class);
        $registry->shouldReceive('execute')->andReturn([
            'output' => 'tool output',
            'error' => null,
            'duration_ms' => 12,
        ]);

        $affective = Mockery::mock(AffectiveStateEngine::class);
        $affective->shouldReceive('shouldPauseForSafety')->andReturn(false);
        $affective->shouldReceive('recordToolResult');

        $runState = Mockery::mock(AgentRunState::class);
        $runState->shouldReceive('recordTool');

        $service = new AgentService(
            Mockery::mock(OllamaService::class),
            $registry,
            $runState,
            $affective,
            Mockery::mock(GoalOwnershipService::class),
            Mockery::mock(RoleProfileService::class),
            Mockery::mock(ProactiveMonitoringService::class),
            Mockery::mock(AgentIdentityService::class),
        );

        $method = new \ReflectionMethod($service, 'executeSingleTool');
        $method->setAccessible(true);
        $method->invoke(
            $service,
            ['function' => ['name' => 'web', 'arguments' => ['action' => 'search']]],
            $conversation,
            $assistantMessage,
            'conversation.'.$conversation->id,
            null,
        );

        // No task owns this conversation, so no task-scoped tool events.
        $this->assertDatabaseCount('events', 0);
    }

    public function test_failed_tool_exection_persists_a_failure_event(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->create();
        Task::factory()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
        ]);
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Calling a tool.',
        ]);

        $registry = Mockery::mock(ToolRegistry::class);
        $registry->shouldReceive('execute')->andReturn([
            'output' => '',
            'error' => 'Permission denied',
            'duration_ms' => 3,
        ]);

        $affective = Mockery::mock(AffectiveStateEngine::class);
        $affective->shouldReceive('shouldPauseForSafety')->andReturn(false);
        $affective->shouldReceive('recordToolResult');

        $runState = Mockery::mock(AgentRunState::class);
        $runState->shouldReceive('recordTool');

        $service = new AgentService(
            Mockery::mock(OllamaService::class),
            $registry,
            $runState,
            $affective,
            Mockery::mock(GoalOwnershipService::class),
            Mockery::mock(RoleProfileService::class),
            Mockery::mock(ProactiveMonitoringService::class),
            Mockery::mock(AgentIdentityService::class),
        );

        $method = new \ReflectionMethod($service, 'executeSingleTool');
        $method->setAccessible(true);
        $method->invoke(
            $service,
            ['function' => ['name' => 'shell', 'arguments' => ['command' => 'rm']]],
            $conversation,
            $assistantMessage,
            'conversation.'.$conversation->id,
            null,
        );

        $this->assertDatabaseHas('events', ['event_type' => 'tool.started']);
        $this->assertDatabaseHas('events', ['event_type' => 'tool.failed']);
        $this->assertDatabaseMissing('events', ['event_type' => 'tool.completed']);
    }
}
