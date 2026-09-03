<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Trigger;
use App\Services\Agent\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class CheckTriggersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function handle(AgentService $agent): void
    {
        Trigger::where('is_active', true)
            ->whereIn('type', ['file_watcher', 'url_monitor'])
            ->get()
            ->each(function (Trigger $trigger) use ($agent): void {
                match ($trigger->type) {
                    'file_watcher' => $this->checkFileWatcher($trigger, $agent),
                    'url_monitor' => $this->checkUrlMonitor($trigger, $agent),
                    default => null,
                };
            });
    }

    private function checkFileWatcher(Trigger $trigger, AgentService $agent): void
    {
        $directory = $trigger->config['directory'] ?? null;
        $pattern = $trigger->config['pattern'] ?? '*';

        if (! $directory || ! is_dir($directory)) {
            return;
        }

        $files = glob("{$directory}/{$pattern}") ?: [];
        $latestMtime = 0;
        $newFiles = [];

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
            $lastSeen = (int) ($trigger->last_value ?? 0);
            if ($mtime > $lastSeen) {
                $newFiles[] = basename($file);
            }
        }

        if (empty($newFiles)) {
            return;
        }

        $trigger->update(['last_triggered_at' => now(), 'last_value' => (string) $latestMtime]);

        $prompt = $trigger->prompt."\n\nNew files detected in {$directory}: ".implode(', ', $newFiles);
        $this->dispatchAgent($trigger, $prompt, $agent);
    }

    private function checkUrlMonitor(Trigger $trigger, AgentService $agent): void
    {
        $url = $trigger->config['url'] ?? null;

        if (! $url) {
            return;
        }

        try {
            $response = Http::timeout(10)->get($url);
            $currentHash = md5($response->body());
        } catch (\Throwable) {
            return;
        }

        if ($trigger->last_value === $currentHash) {
            return;
        }

        $trigger->update(['last_triggered_at' => now(), 'last_value' => $currentHash]);

        $prompt = $trigger->prompt."\n\nURL changed: {$url}";
        $this->dispatchAgent($trigger, $prompt, $agent);
    }

    private function dispatchAgent(Trigger $trigger, string $prompt, AgentService $agent): void
    {
        $conversation = Conversation::create(['title' => "Trigger: {$trigger->name}"]);
        $agent->run($conversation, $prompt, "conversation.{$conversation->id}");
    }
}
