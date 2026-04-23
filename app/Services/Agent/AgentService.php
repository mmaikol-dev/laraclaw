<?php

namespace App\Services\Agent;

use App\Events\AgentChunkStreamed;
use App\Events\AgentFinished;
use App\Events\AgentToolCalled;
use App\Models\AgentSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MetricSnapshot;
use App\Models\Skill;
use App\Models\TaskLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;

class AgentService
{
    public function __construct(
        protected OllamaService $ollama,
        protected ToolRegistry $tools,
        protected AgentRunState $runState,
    ) {}

    /**
     * @param  null|callable(array<string, mixed>): void  $listener
     */
    public function run(Conversation $conversation, string $userMessage, string $channelName, ?callable $listener = null): Message
    {
        $this->runState->begin($conversation->id);
        $this->notify($listener, [
            'type' => 'status',
            'status' => 'queued',
            'label' => 'Queued your request.',
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if ($conversation->messages()->count() === 1) {
            $conversation->forceFill([
                'title' => (string) str($userMessage)->squish()->limit(60, ''),
            ])->save();
        }

        $basePrompt = (string) AgentSetting::get(
            'system_prompt',
            "You are LaraClaw, a helpful local AI agent running on the user's Linux machine.",
        );

        $systemPrompt = $basePrompt.$this->buildSkillsContext();
        $history = [['role' => 'system', 'content' => $systemPrompt]];
        $history = [...$history, ...$conversation->fresh()->toOllamaMessages()];

        $tools = $this->tools->toOllamaTools();
        $temperature = (float) AgentSetting::get('temperature', '0.7');
        $maxIterations = (int) AgentSetting::get('max_iterations', 300);
        $tokenBudget = (int) AgentSetting::get('token_budget', 0);
        $summarizeAfter = (int) AgentSetting::get('summarize_after_messages', 0);
        $stopSequences = array_filter(array_map('trim', explode(',', (string) AgentSetting::get('stop_sequences', ''))));
        $totalTokensUsed = 0;
        $cancelled = false;

        if ((bool) AgentSetting::get('enable_planning', false)) {
            $history = $this->runPlanningStep($history, $conversation, $listener);
        }

        try {
            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                if ($this->runState->isCancelled($conversation->id)) {
                    $cancelled = true;
                    break;
                }

                if ($tokenBudget > 0 && $totalTokensUsed >= $tokenBudget) {
                    break;
                }

                if ($summarizeAfter > 0) {
                    $nonSystemCount = count(array_filter($history, fn ($m) => ($m['role'] ?? '') !== 'system'));
                    if ($nonSystemCount > $summarizeAfter) {
                        $history = $this->summarizeHistory($history, $conversation);
                    }
                }

                $this->runState->markThinking($conversation->id);
                $this->notify($listener, [
                    'type' => 'status',
                    'status' => 'thinking',
                    'label' => 'Thinking through the next step.',
                ]);

                $content = '';
                $thinkingContent = '';
                $inThinking = false;
                $toolCalls = [];
                $stopped = false;
                $stats = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_duration_ms' => 0,
                    'load_duration_ms' => 0,
                    'tokens_per_second' => 0,
                ];

                foreach ($this->ollama->chatStream($history, $tools, [
                    'model' => $conversation->model ?: $this->ollama->agentModel,
                    'temperature' => $temperature,
                ]) as $event) {
                    if (($event['type'] ?? null) === 'thinking') {
                        $chunk = (string) ($event['content'] ?? '');

                        if ($chunk === '') {
                            continue;
                        }

                        $thinkingContent .= $chunk;
                        $this->notify($listener, ['type' => 'thinking_chunk', 'content' => $chunk]);

                        continue;
                    }

                    if (($event['type'] ?? null) === 'content') {
                        $chunk = (string) ($event['content'] ?? '');

                        if ($chunk === '') {
                            continue;
                        }

                        if (! $inThinking && $content === '' && $thinkingContent === '' && str_contains($chunk, '<think>')) {
                            $inThinking = true;
                            $chunk = (string) preg_replace('/^.*?<think>/s', '', $chunk);
                        }

                        if ($inThinking) {
                            if (str_contains($chunk, '</think>')) {
                                [$thinkPart, $rest] = explode('</think>', $chunk, 2);
                                $thinkingContent .= $thinkPart;
                                $inThinking = false;
                                $this->notify($listener, ['type' => 'thinking_done', 'content' => $thinkingContent]);
                                $chunk = ltrim($rest);

                                if ($chunk === '') {
                                    continue;
                                }
                            } else {
                                $thinkingContent .= $chunk;
                                $this->notify($listener, ['type' => 'thinking_chunk', 'content' => $chunk]);

                                continue;
                            }
                        }

                        if ($stopSequences !== [] && $this->containsStopSequence($content.$chunk, $stopSequences)) {
                            $stopped = true;
                            break;
                        }

                        $content .= $chunk;
                        $this->runState->appendChunk($conversation->id, $chunk);
                        event(new AgentChunkStreamed($channelName, $chunk));
                        $this->notify($listener, ['type' => 'chunk', 'content' => $chunk]);

                        continue;
                    }

                    if (($event['type'] ?? null) === 'tool_call') {
                        $toolCall = $event['tool_call'] ?? null;

                        if (is_array($toolCall)) {
                            $toolCalls[] = $toolCall;
                        }

                        continue;
                    }

                    if (($event['type'] ?? null) === 'done' && is_array($event['stats'] ?? null)) {
                        $stats = $event['stats'];
                    }
                }

                $content = trim($content);
                $totalTokensUsed += (int) $stats['prompt_tokens'] + (int) $stats['completion_tokens'];
                $model = $conversation->model ?: $this->ollama->agentModel;

                if (
                    $content === ''
                    && $toolCalls === []
                    && ! $stopped
                    && $this->shouldRecoverEmptyResponse($thinkingContent, $stats)
                ) {
                    $recoveredContent = $this->recoverEmptyResponse($history, $model, $temperature);

                    if ($recoveredContent !== '') {
                        $content = $recoveredContent;
                        $this->runState->appendChunk($conversation->id, $content);
                        event(new AgentChunkStreamed($channelName, $content));
                        $this->notify($listener, ['type' => 'chunk', 'content' => $content]);
                    }
                }

                if ($toolCalls === [] || $stopped) {
                    $assistantMessage = $conversation->messages()->create([
                        'role' => 'assistant',
                        'content' => $content !== '' ? $content : 'The model returned an empty response.',
                        'thinking' => $thinkingContent !== '' ? $thinkingContent : null,
                        'prompt_tokens' => (int) $stats['prompt_tokens'],
                        'completion_tokens' => (int) $stats['completion_tokens'],
                        'tokens_per_second' => (float) $stats['tokens_per_second'],
                        'duration_ms' => (int) $stats['total_duration_ms'],
                    ]);

                    MetricSnapshot::record($stats, $model);
                    $conversation->increment('total_tokens', (int) $stats['prompt_tokens'] + (int) $stats['completion_tokens']);

                    event(new AgentFinished($channelName, $assistantMessage->id, $stats));
                    $this->runState->finish($conversation->id, $assistantMessage->id, $stats);
                    $this->notify($listener, [
                        'type' => 'done',
                        'message_id' => $assistantMessage->id,
                        'stats' => $stats,
                    ]);

                    return $assistantMessage;
                }

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $content !== '' ? $content : null,
                    'thinking' => $thinkingContent !== '' ? $thinkingContent : null,
                    'tool_calls' => $toolCalls,
                    'prompt_tokens' => (int) $stats['prompt_tokens'],
                    'completion_tokens' => (int) $stats['completion_tokens'],
                    'tokens_per_second' => (float) $stats['tokens_per_second'],
                    'duration_ms' => (int) $stats['total_duration_ms'],
                ]);
                $history[] = $assistantMessage->toOllamaFormat();

                $toolMessages = $this->executeTools($toolCalls, $conversation, $assistantMessage, $channelName, $listener);

                foreach ($toolMessages as $toolMessage) {
                    $history[] = $toolMessage->toOllamaFormat();
                }

                if ((bool) AgentSetting::get('enable_reflection', false)) {
                    $history = $this->runReflectionStep($history, $conversation, $listener);
                }
            }
        } catch (\Throwable $exception) {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'LaraClaw could not complete that request: '.$exception->getMessage(),
            ]);
            $this->runState->fail($conversation->id, 'The agent run failed.');
            $this->notify($listener, ['type' => 'error', 'message' => $exception->getMessage()]);

            $emptyStats = ['tokens_per_second' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_duration_ms' => 0, 'load_duration_ms' => 0];
            event(new AgentFinished($channelName, $assistantMessage->id, $emptyStats));

            return $assistantMessage;
        }

        $reason = match (true) {
            $cancelled => 'Stopped by user.',
            $tokenBudget > 0 && $totalTokensUsed >= $tokenBudget => "Stopped: token budget of {$tokenBudget} reached.",
            default => 'The agent reached its tool-iteration limit before finishing.',
        };

        $assistantMessage = $conversation->messages()->create(['role' => 'assistant', 'content' => $reason]);
        $emptyStats = ['tokens_per_second' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_duration_ms' => 0, 'load_duration_ms' => 0];
        $this->runState->finish($conversation->id, $assistantMessage->id, $emptyStats);
        $this->notify($listener, ['type' => 'done', 'message_id' => $assistantMessage->id, 'stats' => $emptyStats]);
        event(new AgentFinished($channelName, $assistantMessage->id, $emptyStats));

        return $assistantMessage;
    }

    /**
     * @param  array<string, int|float>  $stats
     */
    private function shouldRecoverEmptyResponse(string $thinkingContent, array $stats): bool
    {
        return trim($thinkingContent) !== '' || (int) ($stats['completion_tokens'] ?? 0) > 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $history
     */
    private function recoverEmptyResponse(array $history, string $model, float $temperature): string
    {
        $recoveryResponse = $this->ollama->chat([
            ...$history,
            [
                'role' => 'user',
                'content' => 'Provide the final answer to the last request now. Do not call tools. Do not include hidden reasoning.',
            ],
        ], [], [
            'model' => $model,
            'temperature' => $temperature,
        ]);

        return trim((string) data_get($recoveryResponse, 'message.content', ''));
    }

    // -------------------------------------------------------------------------
    // Tool execution
    // -------------------------------------------------------------------------

    /**
     * Dispatch tool calls — parallel when multiple are present and the setting is on.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, Message>
     */
    private function executeTools(array $toolCalls, Conversation $conversation, Message $assistantMessage, string $channelName, ?callable $listener): array
    {
        if (count($toolCalls) > 1 && (bool) AgentSetting::get('parallel_tools', true)) {
            try {
                return $this->executeToolsParallel($toolCalls, $conversation, $assistantMessage, $channelName, $listener);
            } catch (\Throwable) {
                // Fall back to sequential if Concurrency is unavailable or fails.
            }
        }

        return $this->executeToolsSequential($toolCalls, $conversation, $assistantMessage, $channelName, $listener);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, Message>
     */
    private function executeToolsSequential(array $toolCalls, Conversation $conversation, Message $assistantMessage, string $channelName, ?callable $listener): array
    {
        return array_map(
            fn (array $toolCall) => $this->executeSingleTool($toolCall, $conversation, $assistantMessage, $channelName, $listener),
            $toolCalls,
        );
    }

    /**
     * Run all tool calls concurrently using Laravel Concurrency (forked processes).
     * Task logs are created before forking so the UI updates immediately.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, Message>
     */
    private function executeToolsParallel(array $toolCalls, Conversation $conversation, Message $assistantMessage, string $channelName, ?callable $listener): array
    {
        $taskLogs = [];

        foreach ($toolCalls as $index => $toolCall) {
            $function = $toolCall['function'] ?? [];
            $toolName = (string) ($function['name'] ?? 'unknown');
            $arguments = $this->parseArguments($function['arguments'] ?? []);

            $taskLog = TaskLog::query()->create([
                'conversation_id' => $conversation->id,
                'message_id' => $assistantMessage->id,
                'tool_name' => $toolName,
                'tool_input' => $arguments,
                'status' => 'pending',
            ]);
            $taskLog->markRunning();
            $taskLogs[$index] = $taskLog;

            event(new AgentToolCalled($channelName, [
                'id' => $taskLog->id,
                'tool_name' => $toolName,
                'input' => $arguments,
                'status' => 'running',
            ]));
            $this->notify($listener, ['type' => 'tool', 'tool' => [
                'id' => $taskLog->id,
                'tool_name' => $toolName,
                'input' => $arguments,
                'status' => 'running',
            ]]);
        }

        $closures = array_map(function (array $toolCall): \Closure {
            $function = $toolCall['function'] ?? [];
            $toolName = (string) ($function['name'] ?? 'unknown');
            $arguments = $this->parseArguments($function['arguments'] ?? []);
            $cacheTtl = (int) AgentSetting::get('tool_cache_ttl', 0);
            $cacheKey = 'tool_result:'.md5($toolName.serialize($arguments));

            return function () use ($toolName, $arguments, $cacheTtl, $cacheKey): array {
                if ($cacheTtl > 0 && Cache::has($cacheKey)) {
                    return Cache::get($cacheKey);
                }

                $result = app(ToolRegistry::class)->execute($toolName, $arguments);

                if ($cacheTtl > 0 && $result['error'] === null) {
                    Cache::put($cacheKey, $result, $cacheTtl);
                }

                return $result;
            };
        }, $toolCalls);

        $results = Concurrency::run($closures);

        $messages = [];

        foreach ($toolCalls as $index => $toolCall) {
            $function = $toolCall['function'] ?? [];
            $toolName = (string) ($function['name'] ?? 'unknown');
            $arguments = $this->parseArguments($function['arguments'] ?? []);
            $taskLog = $taskLogs[$index];
            $result = $results[$index];

            $toolOutput = $result['error'] !== null ? 'Error: '.$result['error'] : $result['output'];
            $status = $result['error'] === null ? 'success' : 'error';

            if ($result['error'] === null) {
                $taskLog->markSuccess($toolOutput, (int) $result['duration_ms']);
            } else {
                $taskLog->markError((string) $result['error'], (int) $result['duration_ms']);
            }

            event(new AgentToolCalled($channelName, [
                'id' => $taskLog->id,
                'tool_name' => $toolName,
                'input' => $arguments,
                'output' => $toolOutput,
                'status' => $status,
                'duration_ms' => (int) $result['duration_ms'],
            ]));
            $this->notify($listener, ['type' => 'tool', 'tool' => [
                'id' => $taskLog->id,
                'tool_name' => $toolName,
                'input' => $arguments,
                'output' => $toolOutput,
                'status' => $status,
                'duration_ms' => (int) $result['duration_ms'],
            ]]);

            MetricSnapshot::record([
                'tokens_per_second' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_duration_ms' => (int) $result['duration_ms'],
                'load_duration_ms' => 0,
                'extra' => ['status' => $status],
            ], $conversation->model ?: $this->ollama->agentModel, $toolName);

            $messages[] = $conversation->messages()->create([
                'role' => 'tool',
                'content' => $toolOutput,
                'tool_name' => $toolName,
                'tool_result' => ['error' => $result['error'], 'duration_ms' => $result['duration_ms']],
            ]);
        }

        return $messages;
    }

    /**
     * Execute a single tool call with retry and caching.
     */
    private function executeSingleTool(array $toolCall, Conversation $conversation, Message $assistantMessage, string $channelName, ?callable $listener): Message
    {
        $function = $toolCall['function'] ?? [];
        $toolName = (string) ($function['name'] ?? 'unknown');
        $arguments = $this->parseArguments($function['arguments'] ?? []);

        $taskLog = TaskLog::query()->create([
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessage->id,
            'tool_name' => $toolName,
            'tool_input' => $arguments,
            'status' => 'pending',
        ]);
        $taskLog->markRunning();

        event(new AgentToolCalled($channelName, [
            'id' => $taskLog->id,
            'tool_name' => $toolName,
            'input' => $taskLog->tool_input,
            'status' => 'running',
        ]));
        $this->runState->recordTool($conversation->id, [
            'id' => $taskLog->id,
            'tool_name' => $toolName,
            'input' => $taskLog->tool_input,
            'status' => 'running',
        ]);
        $this->notify($listener, ['type' => 'tool', 'tool' => [
            'id' => $taskLog->id,
            'tool_name' => $toolName,
            'input' => $taskLog->tool_input,
            'status' => 'running',
        ]]);

        $result = $this->executeWithRetryAndCache($toolName, $arguments);
        $toolOutput = $result['error'] !== null ? 'Error: '.$result['error'] : $result['output'];
        $status = $result['error'] === null ? 'success' : 'error';

        if ($result['error'] === null) {
            $taskLog->markSuccess($toolOutput, (int) $result['duration_ms']);
        } else {
            $taskLog->markError((string) $result['error'], (int) $result['duration_ms']);
        }

        event(new AgentToolCalled($channelName, [
            'id' => $taskLog->id,
            'tool_name' => $toolName,
            'input' => $taskLog->tool_input,
            'output' => $toolOutput,
            'status' => $status,
            'duration_ms' => (int) $result['duration_ms'],
        ]));
        $this->runState->recordTool($conversation->id, [
            'id' => $taskLog->id,
            'tool_name' => $toolName,
            'input' => $taskLog->tool_input,
            'output' => $toolOutput,
            'status' => $status,
            'duration_ms' => (int) $result['duration_ms'],
        ]);
        $this->notify($listener, ['type' => 'tool', 'tool' => [
            'id' => $taskLog->id,
            'tool_name' => $toolName,
            'input' => $taskLog->tool_input,
            'output' => $toolOutput,
            'status' => $status,
            'duration_ms' => (int) $result['duration_ms'],
        ]]);

        MetricSnapshot::record([
            'tokens_per_second' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_duration_ms' => (int) $result['duration_ms'],
            'load_duration_ms' => 0,
            'extra' => ['status' => $status],
        ], $conversation->model ?: $this->ollama->agentModel, $toolName);

        return $conversation->messages()->create([
            'role' => 'tool',
            'content' => $toolOutput,
            'tool_name' => $toolName,
            'tool_result' => ['error' => $result['error'], 'duration_ms' => $result['duration_ms']],
        ]);
    }

    /**
     * Execute a tool with result caching and retry logic for transient errors.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{output: string, error: string|null, duration_ms: int}
     */
    private function executeWithRetryAndCache(string $toolName, array $arguments): array
    {
        $cacheTtl = (int) AgentSetting::get('tool_cache_ttl', 0);
        $cacheKey = 'tool_result:'.md5($toolName.serialize($arguments));

        if ($cacheTtl > 0 && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $maxRetries = (int) AgentSetting::get('max_tool_retries', 2);
        $attempt = 0;

        while (true) {
            $result = $this->tools->execute($toolName, $arguments);

            if ($result['error'] === null) {
                if ($cacheTtl > 0) {
                    Cache::put($cacheKey, $result, $cacheTtl);
                }

                return $result;
            }

            if ($attempt >= $maxRetries || ! $this->isTransientError((string) $result['error'])) {
                return $result;
            }

            $attempt++;
            usleep(500_000 * $attempt); // 0.5 s, 1 s, …
        }
    }

    /**
     * Return true for errors that are worth retrying (network / timeout).
     */
    private function isTransientError(string $error): bool
    {
        $patterns = ['timed out', 'timeout', 'connection refused', 'network', 'curl error', 'could not connect', 'temporarily unavailable', 'socket'];
        $lower = strtolower($error);

        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Planning & reflection
    // -------------------------------------------------------------------------

    /**
     * Ask the model for a plan before the execution loop begins.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array<string, mixed>>
     */
    private function runPlanningStep(array $history, Conversation $conversation, ?callable $listener): array
    {
        $this->notify($listener, ['type' => 'status', 'status' => 'planning', 'label' => 'Planning approach...']);

        $planHistory = [...$history, [
            'role' => 'user',
            'content' => 'Before taking any actions, briefly outline the steps you will take to accomplish the request. Be concise — no more than 5 bullet points.',
        ]];

        $plan = '';

        try {
            foreach ($this->ollama->chatStream($planHistory, [], [
                'model' => $conversation->model ?: $this->ollama->agentModel,
                'temperature' => 0.3,
            ]) as $event) {
                if (($event['type'] ?? null) === 'content') {
                    $plan .= (string) ($event['content'] ?? '');
                }
            }
        } catch (\Throwable) {
            return $history;
        }

        $plan = trim($plan);

        if ($plan === '') {
            return $history;
        }

        $this->notify($listener, ['type' => 'thinking_chunk', 'content' => "**Plan:**\n".$plan]);

        return [...$history, ['role' => 'assistant', 'content' => '[Plan: '.$plan.']']];
    }

    /**
     * Ask the model to evaluate whether the goal has been achieved after tool use.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array<string, mixed>>
     */
    private function runReflectionStep(array $history, Conversation $conversation, ?callable $listener): array
    {
        $this->notify($listener, ['type' => 'status', 'status' => 'reflecting', 'label' => 'Evaluating progress...']);

        $reflectHistory = [...$history, [
            'role' => 'user',
            'content' => "Based on the tool results above, have you fully accomplished the user's original goal? If yes, confirm briefly. If not, note in one sentence what still needs to be done.",
        ]];

        $reflection = '';

        try {
            foreach ($this->ollama->chatStream($reflectHistory, [], [
                'model' => $conversation->model ?: $this->ollama->agentModel,
                'temperature' => 0.3,
            ]) as $event) {
                if (($event['type'] ?? null) === 'content') {
                    $reflection .= (string) ($event['content'] ?? '');
                }
            }
        } catch (\Throwable) {
            return $history;
        }

        $reflection = trim($reflection);

        if ($reflection === '') {
            return $history;
        }

        $this->notify($listener, ['type' => 'thinking_chunk', 'content' => "**Reflection:**\n".$reflection]);

        return [...$history, ['role' => 'assistant', 'content' => '[Reflection: '.$reflection.']']];
    }

    // -------------------------------------------------------------------------
    // Context summarization
    // -------------------------------------------------------------------------

    /**
     * Replace old conversation turns with a single summary to stay within context limits.
     *
     * @param  array<int, array<string, mixed>>  $history
     * @return array<int, array<string, mixed>>
     */
    private function summarizeHistory(array $history, Conversation $conversation): array
    {
        $keepRecent = 6;
        $systemMessages = array_values(array_filter($history, fn ($m) => ($m['role'] ?? '') === 'system'));
        $nonSystem = array_values(array_filter($history, fn ($m) => ($m['role'] ?? '') !== 'system'));

        if (count($nonSystem) <= $keepRecent) {
            return $history;
        }

        $toSummarize = array_slice($nonSystem, 0, count($nonSystem) - $keepRecent);
        $toKeep = array_slice($nonSystem, count($nonSystem) - $keepRecent);

        $summaryHistory = [
            ...$systemMessages,
            ...$toSummarize,
            ['role' => 'user', 'content' => 'Summarize the conversation and actions taken above in 3–5 sentences. Focus on what was accomplished and any important findings or state.'],
        ];

        $summary = '';

        try {
            foreach ($this->ollama->chatStream($summaryHistory, [], [
                'model' => $conversation->model ?: $this->ollama->agentModel,
                'temperature' => 0.3,
            ]) as $event) {
                if (($event['type'] ?? null) === 'content') {
                    $summary .= (string) ($event['content'] ?? '');
                }
            }
        } catch (\Throwable) {
            return $history;
        }

        $summary = trim($summary);

        if ($summary === '') {
            return $history;
        }

        return [
            ...$systemMessages,
            ['role' => 'assistant', 'content' => '[Summary of earlier conversation: '.$summary.']'],
            ...$toKeep,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return true if accumulated stream content contains a configured stop sequence.
     *
     * @param  array<int, string>  $stopSequences
     */
    private function containsStopSequence(string $content, array $stopSequences): bool
    {
        $lower = strtolower($content);

        foreach ($stopSequences as $sequence) {
            if (str_contains($lower, strtolower($sequence))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise tool arguments — Ollama sometimes sends them as a JSON string.
     *
     * @return array<string, mixed>
     */
    private function parseArguments(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    private function buildSkillsContext(): string
    {
        $skills = Skill::where('is_active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        if ($skills->isEmpty()) {
            return '';
        }

        $lines = $skills->map(fn (Skill $skill): string => "  - [{$skill->category}] {$skill->name}: {$skill->description}")->implode("\n");

        return "\n\n=== Available Skills ===\nYou have the following skills available. Use the skill tool (action: read, name: <skill-name>) to load a skill's full instructions before applying it. Choose the most relevant skill automatically — do not ask the user which to use.\n\n{$lines}\n\nYou may also create new skills, update existing ones, or remove outdated ones using the skill tool as you learn better ways to accomplish tasks.";
    }

    /**
     * @param  null|callable(array<string, mixed>): void  $listener
     * @param  array<string, mixed>  $payload
     */
    private function notify(?callable $listener, array $payload): void
    {
        if ($listener !== null) {
            $listener($payload);
        }
    }
}
