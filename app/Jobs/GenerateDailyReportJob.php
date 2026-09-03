<?php

namespace App\Jobs;

use App\Models\AgentReport;
use App\Models\Conversation;
use App\Models\TaskLog;
use App\Services\Agent\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDailyReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function handle(AgentService $agent): void
    {
        $today = today();

        // Avoid duplicate daily reports
        if (AgentReport::where('report_date', $today)->where('type', 'daily')->exists()) {
            return;
        }

        // Gather today's stats
        $taskCount = TaskLog::whereDate('created_at', $today)->count();
        $successCount = TaskLog::whereDate('created_at', $today)->where('status', 'success')->count();
        $errorCount = TaskLog::whereDate('created_at', $today)->where('status', 'error')->count();
        $toolUsage = TaskLog::whereDate('created_at', $today)
            ->selectRaw('tool_name, count(*) as cnt')
            ->groupBy('tool_name')
            ->pluck('cnt', 'tool_name')
            ->map(fn ($c, $t) => "{$t}: {$c}")
            ->implode(', ');

        $todaysReports = AgentReport::whereDate('report_date', $today)
            ->where('type', 'task_summary')
            ->get()
            ->map(fn ($r) => "- {$r->title}: {$r->content}")
            ->implode("\n");

        $prompt = <<<PROMPT
Write a brief end-of-day standup report for today ({$today->toDateString()}).

Today's activity stats:
- Total tool calls: {$taskCount}
- Successful: {$successCount}
- Errors: {$errorCount}
- Tools used: {$toolUsage}

Scheduled tasks completed today:
{$todaysReports}

Write a concise report covering:
1. What was accomplished today
2. Any issues or errors worth noting
3. What's pending for tomorrow (if any active projects exist)

Keep it to 3-5 sentences. Be factual and direct.
PROMPT;

        $conversation = Conversation::create(['title' => "Daily Report: {$today->toDateString()}"]);
        $message = $agent->run($conversation, $prompt, "conversation.{$conversation->id}");

        AgentReport::create([
            'report_date' => $today,
            'type' => 'daily',
            'title' => "Daily Report — {$today->toDateString()}",
            'content' => $message->content ?? '(no output)',
            'conversation_id' => $conversation->id,
            'meta' => [
                'task_count' => $taskCount,
                'success_count' => $successCount,
                'error_count' => $errorCount,
            ],
        ]);
    }
}
