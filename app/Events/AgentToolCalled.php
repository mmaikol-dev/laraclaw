<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentToolCalled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{id: int, tool_name: string, input: array<string, mixed>, output?: string|null, status: string, duration_ms?: int}  $toolData
     */
    public function __construct(
        public string $channel,
        public array $toolData,
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
        return 'agent.tool';
    }

    /**
     * @return array{id: int, tool_name: string, input: array<string, mixed>, output?: string|null, status: string, duration_ms?: int}
     */
    public function broadcastWith(): array
    {
        return $this->toolData;
    }
}
