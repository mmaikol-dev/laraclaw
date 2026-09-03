<?php

namespace App\Services\Tools;

interface StreamsOutput
{
    /**
     * Execute the tool while streaming incremental output chunks to a callback.
     *
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): void  $onOutput
     */
    public function executeStreaming(array $arguments, callable $onOutput): string;
}
