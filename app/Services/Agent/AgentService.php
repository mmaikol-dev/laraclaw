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

        $userEntry = $conversation->messages()->create([
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

        $history = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
        ];
        $history = [...$history, ...$conversation->fresh()->toOllamaMessages()];
        $tools = $this->tools->toOllamaTools();
        $temperature = (float) AgentSetting::get('temperature', '0.7');
        $maxIterations = 300;

        $cancelled = false;

        try {
            for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
                if ($this->runState->isCancelled($conversation->id)) {
                    $cancelled = true;
                    break;
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
                    if (($event['type'] ?? null) === 'content') {
                        $chunk = (string) ($event['content'] ?? '');

                        if ($chunk === '') {
                            continue;
                        }

                        // Detect <think>…</think> reasoning blocks emitted by models like qwen3, deepseek-r1.
                        if (! $inThinking && $content === '' && $thinkingContent === '' && str_contains($chunk, '<think>')) {
                            $inThinking = true;
                            $chunk = (string) preg_replace('/^.*?<think>/s', '', $chunk);
                        }

                        if ($inThinking) {
                            if (str_contains($chunk, '</think>')) {
                                [$thinkPart, $rest] = explode('</think>', $chunk, 2);
                                $thinkingContent .= $thinkPart;
                                $inThinking = false;

                                $this->notify($listener, [
                                    'type' => 'thinking_done',
                                    'content' => $thinkingContent,
                                ]);

                                $chunk = ltrim($rest);

                                if ($chunk === '') {
                                    continue;
                                }
                            } else {
                                $thinkingContent .= $chunk;
                                $this->notify($listener, [
                                    'type' => 'thinking_chunk',
                                    'content' => $chunk,
                                ]);

                                continue;
                            }
                        }

                        $content .= $chunk;
                        $this->runState->appendChunk($conversation->id, $chunk);
                        event(new AgentChunkStreamed($channelName, $chunk));
                        $this->notify($listener, [
                            'type' => 'chunk',
                            'content' => $chunk,
                        ]);

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

                if ($toolCalls === []) {
                    $assistantMessage = $conversation->messages()->create([
                        'role' => 'assistant',
                        'content' => $content !== '' ? $content : 'The model returned an empty response.',
                        'thinking' => $thinkingContent !== '' ? $thinkingContent : null,
                        'prompt_tokens' => (int) $stats['prompt_tokens'],
                        'completion_tokens' => (int) $stats['completion_tokens'],
                        'tokens_per_second' => (float) $stats['tokens_per_second'],
                        'duration_ms' => (int) $stats['total_duration_ms'],
                    ]);

                    MetricSnapshot::record($stats, $conversation->model ?: $this->ollama->agentModel);
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

                foreach ($toolCalls as $toolCall) {
                    $function = $toolCall['function'] ?? [];
                    $toolName = (string) ($function['name'] ?? 'unknown');
                    $arguments = $function['arguments'] ?? [];

                    if (is_string($arguments)) {
                        $decodedArguments = json_decode($arguments, true);
                        $arguments = is_array($decodedArguments) ? $decodedArguments : [];
                    }

                    $taskLog = TaskLog::query()->create([
                        'conversation_id' => $conversation->id,
                        'message_id' => $assistantMessage->id,
                        'tool_name' => $toolName,
                        'tool_input' => is_array($arguments) ? $arguments : [],
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
                    $this->notify($listener, [
                        'type' => 'tool',
                        'tool' => [
                            'id' => $taskLog->id,
                            'tool_name' => $toolName,
                            'input' => $taskLog->tool_input,
                            'status' => 'running',
                        ],
                    ]);

                    $result = $this->tools->execute($toolName, is_array($arguments) ? $arguments : []);
                    $toolOutput = $result['error'] !== null
                        ? 'Error: '.$result['error']
                        : $result['output'];

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
                        'status' => $result['error'] === null ? 'success' : 'error',
                        'duration_ms' => (int) $result['duration_ms'],
                    ]));
                    $this->runState->recordTool($conversation->id, [
                        'id' => $taskLog->id,
                        'tool_name' => $toolName,
                        'input' => $taskLog->tool_input,
                        'output' => $toolOutput,
                        'status' => $result['error'] === null ? 'success' : 'error',
                        'duration_ms' => (int) $result['duration_ms'],
                    ]);
                    $this->notify($listener, [
                        'type' => 'tool',
                        'tool' => [
                            'id' => $taskLog->id,
                            'tool_name' => $toolName,
                            'input' => $taskLog->tool_input,
                            'output' => $toolOutput,
                            'status' => $result['error'] === null ? 'success' : 'error',
                            'duration_ms' => (int) $result['duration_ms'],
                        ],
                    ]);

                    MetricSnapshot::record([
                        'tokens_per_second' => 0,
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'total_duration_ms' => (int) $result['duration_ms'],
                        'load_duration_ms' => 0,
                        'extra' => [
                            'status' => $result['error'] === null ? 'success' : 'error',
                        ],
                    ], $conversation->model ?: $this->ollama->agentModel, $toolName);

                    $toolMessage = $conversation->messages()->create([
                        'role' => 'tool',
                        'content' => $toolOutput,
                        'tool_name' => $toolName,
                        'tool_result' => [
                            'error' => $result['error'],
                            'duration_ms' => $result['duration_ms'],
                        ],
                    ]);
                    $history[] = $toolMessage->toOllamaFormat();
                }
            }
        } catch (\Throwable $exception) {
            $assistantMessage = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => 'LaraClaw could not complete that request: '.$exception->getMessage(),
            ]);
            $this->runState->fail($conversation->id, 'The agent run failed.');
            $this->notify($listener, [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);

            event(new AgentFinished($channelName, $assistantMessage->id, [
                'tokens_per_second' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_duration_ms' => 0,
                'load_duration_ms' => 0,
            ]));

            return $assistantMessage;
        }

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $cancelled
                ? 'Stopped by user.'
                : 'The agent reached its tool-iteration limit before finishing.',
        ]);
        $this->runState->finish($conversation->id, $assistantMessage->id, [
            'tokens_per_second' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_duration_ms' => 0,
            'load_duration_ms' => 0,
        ]);
        $this->notify($listener, [
            'type' => 'done',
            'message_id' => $assistantMessage->id,
            'stats' => [
                'tokens_per_second' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_duration_ms' => 0,
                'load_duration_ms' => 0,
            ],
        ]);
        event(new AgentFinished($channelName, $assistantMessage->id, [
            'tokens_per_second' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_duration_ms' => 0,
            'load_duration_ms' => 0,
        ]));

        return $assistantMessage;
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

        $lines = $skills->map(function (Skill $skill): string {
            return "  - [{$skill->category}] {$skill->name}: {$skill->description}";
        })->implode("\n");

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
