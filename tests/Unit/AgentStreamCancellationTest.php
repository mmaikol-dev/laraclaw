<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Services\Agent\AffectiveStateEngine;
use App\Services\Agent\AgentIdentityService;
use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use App\Services\Agent\GoalOwnershipService;
use App\Services\Agent\OllamaService;
use App\Services\Agent\OutcomeRecorder;
use App\Services\Agent\ProactiveMonitoringService;
use App\Services\Agent\RoleProfileService;
use App\Services\Agent\ToolRegistry;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AgentStreamCancellationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return Generator<int, array<string, mixed>>
     */
    private function stream(array $events): Generator
    {
        foreach ($events as $event) {
            yield $event;
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_stopping_during_a_long_stream_halts_it_before_the_response_finishes(): void
    {
        $conversation = Conversation::factory()->create();

        $events = [];
        for ($i = 0; $i < 400; $i++) {
            $events[] = ['type' => 'content', 'content' => 'word '];
        }

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->agentModel = 'test-model';
        $ollama->shouldReceive('chatStream')->once()->andReturn($this->stream($events));

        // First poll is the per-iteration boundary check, which passes so the
        // stream starts. The next poll happens mid-stream and reports the stop.
        $runState = Mockery::mock(AgentRunState::class);
        $runState->shouldReceive('begin')->andReturn([]);
        $runState->shouldReceive('isCancelled')->andReturn(false, true);
        $runState->shouldReceive('markThinking')->andReturn([]);
        $runState->shouldReceive('appendChunk')->andReturnNull();
        $runState->shouldReceive('recordTool')->andReturn([]);
        $runState->shouldReceive('finish')->andReturn([]);
        $runState->shouldReceive('fail')->andReturn([]);

        $affective = Mockery::mock(AffectiveStateEngine::class);
        $affective->shouldReceive('begin')->andReturn([]);
        $affective->shouldReceive('recordAssistantTurn')->andReturn([]);
        $affective->shouldReceive('recordException')->andReturn([]);
        $affective->shouldReceive('buildContext')->andReturn('');
        $affective->shouldReceive('shouldPauseForSafety')->andReturnFalse();
        $affective->shouldReceive('blockMessage')->andReturnFalse();

        $goals = Mockery::mock(GoalOwnershipService::class);
        $goals->shouldReceive('beginRun')->andReturnNull();
        $goals->shouldReceive('buildPromptContext')->andReturn('');
        $goals->shouldReceive('finalizeRun')->andReturnNull();

        $roles = Mockery::mock(RoleProfileService::class);
        $roles->shouldReceive('resolveForConversation')->andReturnNull();
        $roles->shouldReceive('buildPromptContext')->andReturn('');

        $monitoring = Mockery::mock(ProactiveMonitoringService::class);
        $monitoring->shouldReceive('refreshEnvironmentAwareness')->andReturn([]);
        $monitoring->shouldReceive('refreshFindings')->andReturn([]);
        $monitoring->shouldReceive('buildPromptContext')->andReturn('');

        $identity = Mockery::mock(AgentIdentityService::class);
        $identity->shouldReceive('buildPromptContext')->andReturn('');
        $identity->shouldReceive('rememberRunOutcome')->andReturnNull();

        $tools = Mockery::mock(ToolRegistry::class);
        $tools->shouldReceive('bindRole')->andReturnNull();
        $tools->shouldReceive('toOllamaTools')->andReturn([]);
        $tools->shouldReceive('all')->andReturn([]);

        $service = new AgentService(
            $ollama,
            $tools,
            $runState,
            $affective,
            $goals,
            $roles,
            $monitoring,
            $identity,
            new OutcomeRecorder,
        );

        $message = $service->run($conversation, 'Write me a very long response', 'test');

        $this->assertSame('Stopped by user.', $message->content);
    }

    public function test_a_healthy_run_is_not_cut_short_by_cancellation_polling(): void
    {
        $conversation = Conversation::factory()->create();

        $events = [['type' => 'content', 'content' => 'All done.']];

        $ollama = Mockery::mock(OllamaService::class);
        $ollama->agentModel = 'test-model';
        $ollama->shouldReceive('chatStream')->once()->andReturn($this->stream($events));

        $runState = Mockery::mock(AgentRunState::class);
        $runState->shouldReceive('begin')->andReturn([]);
        $runState->shouldReceive('isCancelled')->andReturn(false);
        $runState->shouldReceive('markThinking')->andReturn([]);
        $runState->shouldReceive('appendChunk')->andReturnNull();
        $runState->shouldReceive('finish')->andReturn([]);
        $runState->shouldReceive('fail')->andReturn([]);

        $affective = Mockery::mock(AffectiveStateEngine::class);
        $affective->shouldReceive('begin')->andReturn([]);
        $affective->shouldReceive('recordAssistantTurn')->andReturn([]);
        $affective->shouldReceive('recordException')->andReturn([]);
        $affective->shouldReceive('buildContext')->andReturn('');
        $affective->shouldReceive('shouldPauseForSafety')->andReturnFalse();
        $affective->shouldReceive('blockMessage')->andReturnFalse();

        $goals = Mockery::mock(GoalOwnershipService::class);
        $goals->shouldReceive('beginRun')->andReturnNull();
        $goals->shouldReceive('buildPromptContext')->andReturn('');
        $goals->shouldReceive('finalizeRun')->andReturnNull();

        $roles = Mockery::mock(RoleProfileService::class);
        $roles->shouldReceive('resolveForConversation')->andReturnNull();
        $roles->shouldReceive('buildPromptContext')->andReturn('');

        $monitoring = Mockery::mock(ProactiveMonitoringService::class);
        $monitoring->shouldReceive('refreshEnvironmentAwareness')->andReturn([]);
        $monitoring->shouldReceive('refreshFindings')->andReturn([]);
        $monitoring->shouldReceive('buildPromptContext')->andReturn('');

        $identity = Mockery::mock(AgentIdentityService::class);
        $identity->shouldReceive('buildPromptContext')->andReturn('');
        $identity->shouldReceive('rememberRunOutcome')->andReturnNull();

        $tools = Mockery::mock(ToolRegistry::class);
        $tools->shouldReceive('bindRole')->andReturnNull();
        $tools->shouldReceive('toOllamaTools')->andReturn([]);
        $tools->shouldReceive('all')->andReturn([]);

        $service = new AgentService(
            $ollama,
            $tools,
            $runState,
            $affective,
            $goals,
            $roles,
            $monitoring,
            $identity,
            new OutcomeRecorder,
        );

        $message = $service->run($conversation, 'Say something short', 'test');

        $this->assertSame('All done.', $message->content);
    }
}
