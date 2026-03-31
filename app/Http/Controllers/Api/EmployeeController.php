<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Agent\OllamaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeController extends Controller
{
    public function __construct(private readonly OllamaService $ollama) {}

    /**
     * Enhance a piece of text using the AI model.
     */
    public function enhance(Request $request): JsonResponse
    {
        $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'context' => ['nullable', 'string', 'max:200'],
        ]);

        $text = $request->string('text')->trim()->toString();
        $context = $request->string('context', 'task description')->trim()->toString();

        $result = $this->ollama->chat([
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant that improves user input. Rewrite the given text to be clearer, more specific, and more actionable. Keep it concise. Return only the improved text, nothing else.',
            ],
            [
                'role' => 'user',
                'content' => "Improve this {$context}:\n\n{$text}",
            ],
        ], [], ['temperature' => 0.4]);

        $enhanced = trim((string) ($result['message']['content'] ?? $text));

        return response()->json(['enhanced' => $enhanced]);
    }

    /**
     * Convert the simple form into a natural-language prompt and return a conversation ID.
     */
    public function create(Request $request): JsonResponse
    {
        $request->validate([
            'task' => ['required', 'string', 'max:500'],
            'when' => ['required', 'string', 'in:manual,daily,weekly,file,url,webhook'],
            'time' => ['nullable', 'string'],
            'day' => ['nullable', 'string'],
            'directory' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:500'],
            'memory' => ['nullable', 'string', 'max:1000'],
        ]);

        $prompt = $this->buildPrompt($request);

        $conversation = Conversation::create([
            'user_id' => Auth::id(),
            'title' => 'Employee: '.str((string) $request->string('task'))->limit(60),
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'prompt' => $prompt,
        ]);
    }

    private function buildPrompt(Request $request): string
    {
        $task = $request->string('task')->trim();
        $when = (string) $request->string('when');
        $time = $request->string('time', '09:00');
        $day = $request->string('day', 'monday');
        $directory = $request->string('directory')->trim();
        $url = $request->string('url')->trim();
        $memory = $request->string('memory')->trim();

        $parts = [];

        $parts[] = match ($when) {
            'daily' => "Create a scheduled task that runs every day at {$time} with the following instructions: {$task}",
            'weekly' => "Create a scheduled task that runs every {$day} at {$time} with the following instructions: {$task}",
            'file' => "Create a file watcher trigger that monitors the directory \"{$directory}\" and when new files arrive, runs the following instructions: {$task}",
            'url' => "Create a URL monitor trigger that watches \"{$url}\" for changes and when something changes, runs the following instructions: {$task}",
            'webhook' => "Create a webhook trigger with the following instructions to run when it receives a request: {$task}",
            default => "Do the following right now: {$task}",
        };

        if ((string) $memory !== '') {
            $parts[] = "Also remember this for future reference: {$memory}";
        }

        return implode('. ', $parts).'.';
    }
}
