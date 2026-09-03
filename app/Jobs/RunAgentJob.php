<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Agent\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAgentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $conversationId,
        public string $userMessage,
    ) {}

    public function handle(AgentService $agent): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if ($conversation === null) {
            return;
        }

        $agent->run($conversation, $this->userMessage, "conversation.{$this->conversationId}");
    }
}
