<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentChunkStreamed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $channel,
        public string $content,
    ) {}

    /**
     * @return Channel|array<int, Channel>
     */
    public function broadcastOn(): Channel|array
    {
        return new Channel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'agent.chunk';
    }

    /**
     * @return array{content: string}
     */
    public function broadcastWith(): array
    {
        return [
            'content' => $this->content,
        ];
    }
}
