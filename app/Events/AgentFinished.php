<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentFinished implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, int|float|string|array<mixed>|null>  $stats
     */
    public function __construct(
        public string $channel,
        public int $messageId,
        public array $stats,
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
        return 'agent.done';
    }

    /**
     * @return array{message_id: int, stats: array<string, int|float|string|array<mixed>|null>}
     */
    public function broadcastWith(): array
    {
        return [
            'message_id' => $this->messageId,
            'stats' => $this->stats,
        ];
    }
}
