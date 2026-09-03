<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendConversationMessageRequest;
use App\Http\Requests\StoreConversationRequest;
use App\Http\Requests\UpdateConversationTitleRequest;
use App\Jobs\RunAgentJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agent\AgentDispatchMode;
use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function __construct(
        protected AgentDispatchMode $dispatchMode,
        protected AgentRunState $runState,
    ) {}

    public function index(): JsonResponse
    {
        $conversations = Conversation::query()
            ->where('is_archived', false)
            ->withCount('messages')
            ->latest('updated_at')
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
                'message_count' => $conversation->messages_count,
                'updated_at' => $conversation->updated_at?->toISOString(),
            ]);

        return response()->json([
            'data' => $conversations,
        ]);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = Conversation::query()->create([
            'title' => $request->validated('title') ?: 'New conversation',
            'model' => $request->validated('model') ?: config('ollama.agent_model'),
        ]);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
                'updated_at' => $conversation->updated_at?->toISOString(),
            ],
        ], 201);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load([
            'messages' => fn ($query) => $query->orderBy('created_at'),
            'messages.taskLogs',
        ]);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'model' => $conversation->model,
                'is_archived' => $conversation->is_archived,
                'stream' => $this->runState->get($conversation->id),
                'messages' => $conversation->messages->map(
                    fn (Message $message): array => [
                        'id' => $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'tool_calls' => $message->tool_calls,
                        'tool_name' => $message->tool_name,
                        'thinking' => $message->thinking,
                        'task_logs' => $message->role === 'assistant'
                            ? $message->taskLogs->map(fn ($log): array => [
                                'id' => $log->id,
                                'tool_name' => $log->tool_name,
                                'tool_input' => $log->tool_input,
                                'tool_output' => $log->tool_output,
                                'status' => $log->status,
                                'duration_ms' => $log->duration_ms,
                                'error_message' => $log->error_message,
                            ])->values()
                            : [],
                        'stats' => [
                            'tokens_per_second' => $message->tokens_per_second,
                            'duration_ms' => $message->duration_ms,
                        ],
                        'created_at' => $message->created_at?->toISOString(),
                    ],
                )->values(),
            ],
        ]);
    }

    public function sendMessage(SendConversationMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $messageText = $request->validated('message');
        $mode = $this->dispatchMode->current();
        $this->runState->begin($conversation->id);

        if ($this->dispatchMode->shouldRunAfterResponse()) {
            RunAgentJob::dispatchAfterResponse($conversation->id, $messageText);
        } elseif ($this->dispatchMode->shouldQueue()) {
            RunAgentJob::dispatch($conversation->id, $messageText)->onQueue('agent');
        } else {
            RunAgentJob::dispatchSync($conversation->id, $messageText);
            $mode = 'completed';
        }

        return response()->json([
            'status' => $mode,
            'channel' => "conversation.{$conversation->id}",
            'conversation_id' => $conversation->id,
        ], $mode === 'completed' ? 200 : 202);
    }

    public function streamMessage(
        SendConversationMessageRequest $request,
        Conversation $conversation,
        AgentService $agent,
    ): StreamedResponse {
        $messageText = $request->validated('message');

        return response()->stream(function () use ($agent, $conversation, $messageText): void {
            set_time_limit(0);

            $this->emitStreamEvent('status', [
                'status' => 'queued',
                'label' => 'Queued your request.',
            ]);

            $agent->run($conversation, $messageText, "conversation.{$conversation->id}", function (array $event): void {
                $eventName = match ($event['type'] ?? 'status') {
                    'chunk' => 'chunk',
                    'tool' => 'tool',
                    'done' => 'done',
                    'error' => 'error',
                    'thinking_chunk' => 'thinking_chunk',
                    'thinking_done' => 'thinking_done',
                    default => 'status',
                };

                $this->emitStreamEvent($eventName, $event);
            });
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function stream(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'data' => $this->runState->get($conversation->id),
        ]);
    }

    public function updateTitle(UpdateConversationTitleRequest $request, Conversation $conversation): JsonResponse
    {
        $conversation->update([
            'title' => $request->validated('title'),
        ]);

        return response()->json([
            'status' => 'saved',
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
        ]);
    }

    public function cancelStream(Conversation $conversation): JsonResponse
    {
        $this->runState->cancel($conversation->id);

        return response()->json(['status' => 'cancelled']);
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        $conversation->update([
            'is_archived' => true,
        ]);

        return response()->json([
            'status' => 'archived',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitStreamEvent(string $event, array $payload): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        @flush();
    }
}
