<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Project;
use App\Services\Agent\AgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ContinueProjectJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $projectId) {}

    public function handle(AgentService $agent): void
    {
        $project = Project::with('tasks')->find($this->projectId);

        if ($project === null || ! in_array($project->status, ['active', 'pending'], true)) {
            return;
        }

        $conversation = $project->conversation_id
            ? $project->conversation
            : Conversation::create(['title' => "Project: {$project->name}"]);

        if ($conversation === null) {
            $conversation = Conversation::create(['title' => "Project: {$project->name}"]);
        }

        $taskList = $project->tasks->map(fn ($t) => "  [{$t->status}] {$t->title}".($t->notes ? " — {$t->notes}" : ''))->implode("\n");

        $prompt = <<<PROMPT
You are working on the project: "{$project->name}"

Goal: {$project->goal}

Current task list:
{$taskList}

Progress so far: {$project->progressSummary()}

Previous notes: {$project->progress_notes}

Please continue working on this project. Pick the next pending or blocked task, work on it using your tools, update the project task status via the project tool, and add a progress note when done.
PROMPT;

        $project->update(['status' => 'active', 'started_at' => $project->started_at ?? now()]);
        $project->update(['conversation_id' => $conversation->id]);

        $agent->run($conversation, $prompt, "conversation.{$conversation->id}");
    }
}
