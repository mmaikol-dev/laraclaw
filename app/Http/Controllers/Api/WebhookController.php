<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunAgentJob;
use App\Models\Conversation;
use App\Models\Trigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function receive(Request $request, string $triggerName): JsonResponse
    {
        $trigger = Trigger::where('name', $triggerName)
            ->where('type', 'webhook')
            ->where('is_active', true)
            ->first();

        if ($trigger === null) {
            return response()->json(['error' => 'Trigger not found.'], 404);
        }

        // Validate secret if configured
        if ($trigger->webhook_secret) {
            $provided = $request->header('X-Webhook-Secret') ?? $request->query('secret');
            if ($provided !== $trigger->webhook_secret) {
                return response()->json(['error' => 'Invalid secret.'], 403);
            }
        }

        $trigger->update(['last_triggered_at' => now()]);

        $payload = json_encode($request->all(), JSON_PRETTY_PRINT) ?: '{}';
        $prompt = $trigger->prompt."\n\nWebhook payload received:\n{$payload}";

        $conversation = Conversation::create(['title' => "Webhook: {$trigger->name}"]);
        RunAgentJob::dispatch($conversation->id, $prompt);

        return response()->json(['status' => 'queued', 'conversation_id' => $conversation->id]);
    }
}
