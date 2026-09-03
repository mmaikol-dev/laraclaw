<?php

namespace App\Jobs;

use App\Models\AgentReport;
use App\Models\Conversation;
use App\Models\Mission;
use App\Models\ScheduledTask;
use App\Services\Agent\AgentService;
use App\Services\Agent\MissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class RunScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $scheduledTaskId) {}

    public function handle(AgentService $agent, MissionService $missions): void
    {
        $task = ScheduledTask::find($this->scheduledTaskId);

        if ($task === null || ! $task->is_active) {
            return;
        }

        if ($task->run_as_mission) {
            $this->runMissionHeartbeat($task, $missions);

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

    /**
     * Mission-mode fire: instead of one ad-hoc agent conversation, make sure
     * the linked mission exists and claim the next serial work unit so the
     * build keeps progressing across fires. Chaining continues autonomously
     * after each unit; this heartbeat just keeps the engine turning.
     */
    private function runMissionHeartbeat(ScheduledTask $task, MissionService $missions): void
    {
        try {
            $mission = $this->ensureMission($task);
            $mission->refresh();

            if ($mission->status === 'scoping') {
                $scoped = $mission->contractAssertions() !== [] && $mission->features()->count() > 0;

                if ($scoped) {
                    // Unattended approval: the plan exists, start building.
                    $mission->forceFill([
                        'status' => 'active',
                        'started_at' => $mission->started_at ?? now(),
                    ])->save();
                    $missions->queueNextWorkUnit($mission);
                } else {
                    // No plan yet (scoping failed or never ran) — kick it again.
                    AdvanceMissionJob::dispatch($mission->id, 'orchestrator');
                }
            } elseif ($mission->status === 'paused') {
                // A paused mission stays paused — that is an explicit human decision.
            } elseif ($mission->status === 'active' && ! $mission->features()->where('status', 'in_progress')->exists()) {
                $missions->queueNextWorkUnit($mission);
            }

            $this->recordHeartbeatReport($task, $mission);
        } finally {
            $task->updateNextRun();
        }
    }

    private function ensureMission(ScheduledTask $task): Mission
    {
        $existing = $task->mission_id !== null ? Mission::find($task->mission_id) : null;

        if ($existing !== null) {
            return $existing;
        }

        $name = Str::slug($task->name);
        $suffix = 1;

        while (Mission::where('name', $name)->exists()) {
            $name = Str::slug($task->name).'-'.++$suffix;
        }

        $mission = Mission::create([
            'name' => $name,
            'goal' => trim((string) ($task->description !== null && $task->description !== '' ? $task->description : $task->prompt)),
            'status' => 'scoping',
        ]);

        $task->update(['mission_id' => $mission->id]);
        AdvanceMissionJob::dispatch($mission->id, 'orchestrator');

        return $mission;
    }

    private function recordHeartbeatReport(ScheduledTask $task, Mission $mission): void
    {
        $current = $mission->currentFeature();
        $lastHandoff = $mission->handoffs()->first();

        $lines = [
            sprintf('Mission %s [%s] — %s, %s tokens spent.', $mission->name, $mission->status, $mission->progressSummary(), number_format((float) $mission->spent_tokens)),
        ];

        if ($current !== null) {
            $lines[] = "Current feature: [{$current->sort_order}] {$current->title} ({$current->status}).";
        }

        if ($lastHandoff !== null) {
            $lines[] = sprintf('Latest handoff (%s): %s', $lastHandoff->role, str_replace("\n", ' ', (string) $lastHandoff->summary));
        }

        AgentReport::create([
            'report_date' => today(),
            'type' => 'task_summary',
            'title' => "Mission heartbeat: {$task->name}",
            'content' => implode("\n", $lines),
            'conversation_id' => $mission->conversation_id,
            'meta' => [
                'scheduled_task_id' => $task->id,
                'mission_id' => $mission->id,
            ],
        ]);
    }
}
