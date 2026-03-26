<?php

namespace App\Services\Agent;

class AgentDispatchMode
{
    public function current(): string
    {
        return $this->resolve(app()->environment(), (string) config('queue.default'));
    }

    public function shouldRunAfterResponse(): bool
    {
        return $this->current() === 'after_response';
    }

    public function shouldQueue(): bool
    {
        return $this->current() === 'queued';
    }

    public function resolve(string $environment, string $queueConnection): string
    {
        if ($environment === 'local' || $queueConnection === 'sync') {
            return 'after_response';
        }

        return 'queued';
    }
}
