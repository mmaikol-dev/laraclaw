<?php

namespace App\Http\Controllers;

use App\Jobs\ContinueProjectJob;
use App\Jobs\RunScheduledTaskJob;
use App\Models\AgentMemory;
use App\Models\AgentReport;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\Trigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('employee/index');
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'scheduled_tasks' => ScheduledTask::orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'cron_expression' => $t->cron_expression,
                'is_active' => $t->is_active,
                'last_run_at' => $t->last_run_at?->toIso8601String(),
                'next_run_at' => $t->next_run_at?->toIso8601String(),
                'use_same_conversation' => $t->use_same_conversation,
            ]),
            'projects' => Project::with('tasks')->orderByRaw("FIELD(status,'active','pending','paused','completed')")->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'goal' => $p->goal,
                'status' => $p->status,
                'due_date' => $p->due_date?->toDateString(),
                'progress_summary' => $p->progressSummary(),
                'progress_notes' => $p->progress_notes,
                'started_at' => $p->started_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'tasks' => $p->tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'description' => $t->description,
                    'status' => $t->status,
                    'notes' => $t->notes,
                    'completed_at' => $t->completed_at?->toIso8601String(),
                ]),
            ]),
            'triggers' => Trigger::orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'type' => $t->type,
                'config' => $t->config,
                'is_active' => $t->is_active,
                'last_triggered_at' => $t->last_triggered_at?->toIso8601String(),
                'prompt' => $t->prompt,
            ]),
            'memories' => AgentMemory::active()->orderBy('category')->orderBy('key')->get()->map(fn ($m) => [
                'id' => $m->id,
                'key' => $m->key,
                'value' => $m->value,
                'category' => $m->category,
                'tags' => $m->tags,
                'expires_at' => $m->expires_at?->toIso8601String(),
                'updated_at' => $m->updated_at?->toIso8601String(),
            ]),
            'reports' => AgentReport::orderByDesc('created_at')->limit(20)->get()->map(fn ($r) => [
                'id' => $r->id,
                'report_date' => $r->report_date->toDateString(),
                'type' => $r->type,
                'title' => $r->title,
                'content' => $r->content,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function runTask(Request $request, ScheduledTask $scheduledTask): JsonResponse
    {
        RunScheduledTaskJob::dispatch($scheduledTask->id);

        return response()->json(['status' => 'queued']);
    }

    public function toggleTask(Request $request, ScheduledTask $scheduledTask): JsonResponse
    {
        $scheduledTask->update(['is_active' => ! $scheduledTask->is_active]);

        return response()->json(['is_active' => $scheduledTask->is_active]);
    }

    public function deleteTask(ScheduledTask $scheduledTask): JsonResponse
    {
        $scheduledTask->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function continueProject(Project $project): JsonResponse
    {
        ContinueProjectJob::dispatch($project->id);

        return response()->json(['status' => 'queued']);
    }

    public function toggleTrigger(Trigger $trigger): JsonResponse
    {
        $trigger->update(['is_active' => ! $trigger->is_active]);

        return response()->json(['is_active' => $trigger->is_active]);
    }

    public function deleteTrigger(Trigger $trigger): JsonResponse
    {
        $trigger->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function deleteMemory(AgentMemory $agentMemory): JsonResponse
    {
        $agentMemory->delete();

        return response()->json(['status' => 'deleted']);
    }
}
