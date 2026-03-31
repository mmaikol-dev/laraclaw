<?php

namespace App\Jobs;

use App\Models\AgentReport;
use App\Models\Conversation;
use App\Models\ScheduledTask;
use App\Services\Agent\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $scheduledTaskId) {}

    public function handle(AgentService $agent): void
    {
        $task = ScheduledTask::find($this->scheduledTaskId);

        if ($task === null || ! $task->is_active) {
            return;
        }

        // Reuse or create conversation
        $conversation = ($task->use_same_conversation && $task->conversation_id)
            ? $task->conversation
            : Conversation::create(['title' => "Scheduled: {$task->name}"]);

        if ($conversation === null) {
            $conversation = Conversation::create(['title' => "Scheduled: {$task->name}"]);
        }

        $prompt = $task->prompt."\n\n[Scheduled task: {$task->name} | Run at: ".now()->toDateTimeString().']';

        $message = $agent->run($conversation, $prompt, "conversation.{$conversation->id}");

        $task->update(['conversation_id' => $conversation->id]);
        $task->updateNextRun();

        AgentReport::create([
            'report_date' => today(),
            'type' => 'task_summary',
            'title' => "Scheduled task: {$task->name}",
            'content' => $message->content ?? '(no output)',
            'conversation_id' => $conversation->id,
            'meta' => ['scheduled_task_id' => $task->id],
        ]);
    }
}
