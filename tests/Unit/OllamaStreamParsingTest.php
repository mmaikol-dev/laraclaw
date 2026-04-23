<?php

namespace Tests\Unit;

use App\Services\Agent\OllamaService;
use Tests\TestCase;

class OllamaStreamParsingTest extends TestCase
{
    public function test_it_parses_content_chunks_from_stream_lines(): void
    {
        $service = new OllamaService;

        $events = $service->parseStreamLine(json_encode([
            'message' => [
                'role' => 'assistant',
                'content' => 'Hello',
            ],
            'done' => false,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            [
                'type' => 'content',
                'content' => 'Hello',
            ],
        ], $events);
    }

    public function test_it_parses_explicit_thinking_chunks_from_stream_lines(): void
    {
        $service = new OllamaService;

        $events = $service->parseStreamLine(json_encode([
            'message' => [
                'role' => 'assistant',
                'thinking' => 'Let me think about that first.',
                'content' => '',
            ],
            'done' => false,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            [
                'type' => 'thinking',
                'content' => 'Let me think about that first.',
            ],
        ], $events);
    }

    public function test_it_parses_tool_calls_and_done_events_from_stream_lines(): void
    {
        $service = new OllamaService;

        $events = $service->parseStreamLine(json_encode([
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'function' => [
                            'name' => 'file',
                            'arguments' => ['path' => '/tmp/demo.txt'],
                        ],
                    ],
                ],
            ],
            'done' => true,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
            'total_duration' => 2_000_000,
            'load_duration' => 500_000,
            'eval_duration' => 1_000_000_000,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('tool_call', $events[0]['type']);
        $this->assertSame('file', $events[0]['tool_call']['function']['name']);
        $this->assertSame('done', $events[1]['type']);
        $this->assertSame(10, $events[1]['stats']['prompt_tokens']);
        $this->assertSame(5, $events[1]['stats']['completion_tokens']);
    }

    public function test_it_uses_bearer_auth_header_when_ollama_api_key_is_configured(): void
    {
        config()->set('ollama.api_key', 'secret-token');
        config()->set('ollama.headers', []);

        $service = new OllamaService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('curlHeaders');
        $headers = $method->invoke($service);

        $this->assertContains('Authorization: Bearer secret-token', $headers);
    }

    public function test_it_preserves_custom_authorization_header_over_api_key(): void
    {
        config()->set('ollama.api_key', 'secret-token');
        config()->set('ollama.headers', ['Authorization' => 'Token custom-value']);

        $service = new OllamaService;
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('curlHeaders');
        $headers = $method->invoke($service);

        $this->assertContains('Authorization: Token custom-value', $headers);
        $this->assertNotContains('Authorization: Bearer secret-token', $headers);
    }
}
