<?php

namespace App\Services\Agent;

use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaService
{
    private string $host;

    private int $timeout;

    private int $contextLength;

    public function __construct()
    {
        $this->host = rtrim((string) config('ollama.host'), '/');
        $this->agentModel = (string) config('ollama.agent_model');
        $this->embeddingModel = (string) config('ollama.embedding_model');
        $this->timeout = (int) config('ollama.timeout', 120);
        $this->contextLength = (int) config('ollama.context_length', 8192);
    }

    public string $agentModel;

    public string $embeddingModel;

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $payload = [
            'model' => $options['model'] ?? $this->agentModel,
            'messages' => $messages,
            'stream' => false,
            'options' => array_filter([
                'num_ctx' => $this->contextLength,
                'temperature' => $options['temperature'] ?? 0.7,
                ...($options['options'] ?? []),
            ], fn (mixed $value): bool => $value !== null),
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = Http::timeout($this->timeout)->post("{$this->host}/api/chat", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Ollama chat request failed: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $options
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(array $messages, array $tools = [], array $options = []): Generator
    {
        if (! function_exists('curl_init')) {
            yield from $this->fallbackStream($messages, $tools, $options);

            return;
        }

        $payload = [
            'model' => $options['model'] ?? $this->agentModel,
            'messages' => $messages,
            'stream' => true,
            'options' => array_filter([
                'num_ctx' => $this->contextLength,
                'temperature' => $options['temperature'] ?? 0.7,
                ...($options['options'] ?? []),
            ], fn (mixed $value): bool => $value !== null),
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $ch = curl_init("{$this->host}/api/chat");

        if ($ch === false) {
            yield from $this->fallbackStream($messages, $tools, $options);

            return;
        }

        $buffer = '';
        $pendingEvents = [];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$pendingEvents): int {
                $buffer .= $chunk;

                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $position));
                    $buffer = substr($buffer, $position + 1);

                    if ($line === '') {
                        continue;
                    }

                    foreach ($this->parseStreamLine($line) as $event) {
                        $pendingEvents[] = $event;
                    }
                }

                return strlen($chunk);
            },
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        do {
            $status = curl_multi_exec($mh, $active);

            while ($pendingEvents !== []) {
                yield array_shift($pendingEvents);
            }

            if ($active) {
                curl_multi_select($mh, 0.01);
            }
        } while ($active && $status === CURLM_OK);

        while ($pendingEvents !== []) {
            yield array_shift($pendingEvents);
        }

        if ($buffer !== '') {
            foreach ($this->parseStreamLine(trim($buffer)) as $event) {
                yield $event;
            }
        }

        if (curl_errno($ch) !== 0) {
            $error = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            curl_multi_close($mh);

            throw new RuntimeException('Ollama chat stream failed: '.$error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);

        if ($statusCode >= 400) {
            throw new RuntimeException("Ollama chat stream failed with status {$statusCode}.");
        }
    }

    /**
     * @param  string|array<int, string>  $input
     * @return array<int, array<int, float|int>>
     */
    public function embed(string|array $input): array
    {
        $payload = [
            'model' => $this->embeddingModel,
            'input' => is_array($input) ? array_values($input) : [$input],
        ];

        $response = Http::timeout($this->timeout)->post("{$this->host}/api/embed", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Ollama embed request failed: '.$response->body());
        }

        return $response->json('embeddings') ?? [];
    }

    /**
     * @return array{
     *     status: string,
     *     host: string,
     *     agent_model: string,
     *     embedding_model: string,
     *     agent_available: bool,
     *     embedding_available: bool,
     *     available_models: array<int, string>,
     *     message?: string
     * }
     */
    public function healthCheck(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->host}/api/tags");

            if ($response->failed()) {
                throw new RuntimeException($response->body());
            }

            $models = collect($response->json('models') ?? [])
                ->map(fn (array $model): string => (string) ($model['name'] ?? ''))
                ->filter()
                ->values()
                ->all();

            return [
                'status' => 'ok',
                'host' => $this->host,
                'agent_model' => $this->agentModel,
                'embedding_model' => $this->embeddingModel,
                'agent_available' => $this->modelExists($models, $this->agentModel),
                'embedding_available' => $this->modelExists($models, $this->embeddingModel),
                'available_models' => $models,
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'host' => $this->host,
                'agent_model' => $this->agentModel,
                'embedding_model' => $this->embeddingModel,
                'agent_available' => false,
                'embedding_available' => false,
                'available_models' => [],
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<int, string>  $models
     */
    private function modelExists(array $models, string $target): bool
    {
        $prefix = explode(':', $target)[0];

        foreach ($models as $model) {
            if (str_starts_with($model, $target) || str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, int|float>
     */
    private function extractStats(array $response): array
    {
        $promptTokens = (int) ($response['prompt_eval_count'] ?? 0);
        $completionTokens = (int) ($response['eval_count'] ?? 0);
        $evalDuration = (float) ($response['eval_duration'] ?? 0);

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_duration_ms' => (int) round(((float) ($response['total_duration'] ?? 0)) / 1_000_000),
            'load_duration_ms' => (int) round(((float) ($response['load_duration'] ?? 0)) / 1_000_000),
            'tokens_per_second' => $evalDuration > 0
                ? round($completionTokens / ($evalDuration / 1_000_000_000), 2)
                : 0,
        ];
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function fallbackStream(array $messages, array $tools = [], array $options = []): Generator
    {
        $response = $this->chat($messages, $tools, $options);
        $message = $response['message'] ?? [];
        $content = (string) ($message['content'] ?? '');

        foreach (preg_split('/(\s+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            yield [
                'type' => 'content',
                'content' => $chunk,
            ];
        }

        foreach (($message['tool_calls'] ?? []) as $toolCall) {
            yield [
                'type' => 'tool_call',
                'tool_call' => $toolCall,
            ];
        }

        yield [
            'type' => 'done',
            'stats' => $this->extractStats($response),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseStreamLine(string $line): array
    {
        if ($line === '') {
            return [];
        }

        $payload = json_decode($line, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Ollama chat stream returned an invalid JSON chunk.');
        }

        $events = [];
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
        $content = $message['content'] ?? null;

        if (is_string($content) && $content !== '') {
            $events[] = [
                'type' => 'content',
                'content' => $content,
            ];
        }

        foreach (($message['tool_calls'] ?? []) as $toolCall) {
            if (is_array($toolCall)) {
                $events[] = [
                    'type' => 'tool_call',
                    'tool_call' => $toolCall,
                ];
            }
        }

        if (($payload['done'] ?? false) === true) {
            $events[] = [
                'type' => 'done',
                'stats' => $this->extractStats($payload),
            ];
        }

        return $events;
    }
}
