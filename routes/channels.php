<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{id}', function (): bool {
    return true;
});
