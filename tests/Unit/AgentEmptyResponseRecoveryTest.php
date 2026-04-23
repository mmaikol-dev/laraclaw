<?php

namespace Tests\Unit;

use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use App\Services\Agent\OllamaService;
use App\Services\Agent\ToolRegistry;
use Mockery;
use Tests\TestCase;

class AgentEmptyResponseRecoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_attempts_recovery_when_thinking_exists_without_final_content(): void
    {
        $ollama = Mockery::mock(OllamaService::class);
        $ollama->shouldReceive('chat')
            ->once()
            ->withArgs(function (array $history, array $tools, array $options): bool {
                $lastMessage = $history[array_key_last($history)] ?? null;

                return $tools === []
                    && ($options['model'] ?? null) === 'qwen3.5:9b'
                    && ($options['temperature'] ?? null) === 0.7
                    && is_array($lastMessage)
                    && ($lastMessage['role'] ?? null) === 'user'
                    && ($lastMessage['content'] ?? null) === 'Provide the final answer to the last request now. Do not call tools. Do not include hidden reasoning.';
            })
            ->andReturn([
                'message' => [
                    'content' => 'Here are my stored memories.',
                ],
            ]);

        $service = new AgentService(
            $ollama,
            Mockery::mock(ToolRegistry::class),
            Mockery::mock(AgentRunState::class),
        );

        $method = new \ReflectionMethod($service, 'recoverEmptyResponse');
        $method->setAccessible(true);

        $result = $method->invoke($service, [['role' => 'system', 'content' => 'You are helpful.']], 'qwen3.5:9b', 0.7);

        $this->assertSame('Here are my stored memories.', $result);
    }

    public function test_it_marks_empty_response_for_recovery_when_completion_tokens_exist(): void
    {
        $service = new AgentService(
            Mockery::mock(OllamaService::class),
            Mockery::mock(ToolRegistry::class),
            Mockery::mock(AgentRunState::class),
        );

        $method = new \ReflectionMethod($service, 'shouldRecoverEmptyResponse');
        $method->setAccessible(true);

        $result = $method->invoke($service, '', ['completion_tokens' => 18]);

        $this->assertTrue($result);
    }
}
