<?php

namespace App\Services\Tools;

use App\Jobs\AdvanceMissionJob;
use App\Models\AgentSetting;
use App\Models\Mission;
use App\Models\MissionFeature;
use App\Models\MissionHandoff;
use App\Services\Agent\MissionService;
use App\Services\Agent\OpenCodeService;
use RuntimeException;

class MissionTool extends BaseTool
{
    public function __construct(private readonly MissionService $missions) {}

    public function getName(): string
    {
        return 'mission';
    }

    public function getDescription(): string
    {
        return 'Run long-horizon autonomous engineering missions: plan with an orchestrator (features, milestones, validation contract), implement serially with workers, and verify adversarially with validators using structured handoffs.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'list', 'read', 'create', 'update',
                        'set_contract', 'add_feature', 'update_feature',
                        'start', 'advance', 'pause', 'resume', 'complete', 'fail',
                        'record_handoff', 'add_context_note',
                        'set_models', 'list_models',
                    ],
                    'description' => implode(' ', [
                        'list: show all missions.',
                        'read: full mission plan, features, contract, and latest handoffs.',
                        'create: start a new mission — pass name and goal.',
                        'update: change name, goal, or status.',
                        'set_contract: replace the Validation Contract — pass mission_name and assertions (JSON array of strings).',
                        'add_feature: append a planned feature — pass mission_name, feature_title, feature_description, milestone.',
                        'update_feature: change a feature\'s status/notes — pass mission_name, feature_title, feature_status, notes.',
                        'start: activate a scoped mission and begin serial execution.',
                        'advance: queue one serial work unit (next worker or validator step) for autonomous execution.',
                        'pause / resume / complete / fail: change mission state.',
                        'record_handoff: structured handoff after finishing a role — pass mission_name, role, summary, completed_work, undone_work, execution_log, discovered_issues, procedure_adhered.',
                        'add_context_note: persist context for future workers — pass mission_name and note.',
                        'set_models: assign models per role (Droid Whispering) — pass mission_name plus any of orchestrator_model, worker_model, validator_model.',
                        'list_models: show opencode models available for role assignment.',
                    ]),
                ],
                'mission_name' => ['type' => 'string', 'description' => 'Mission name.'],
                'name' => ['type' => 'string', 'description' => 'New mission name (create/update).'],
                'goal' => ['type' => 'string', 'description' => 'The high-level outcome (create/update).'],
                'status' => ['type' => 'string', 'enum' => Mission::STATUSES, 'description' => 'Mission status (update).'],
                'assertions' => ['type' => 'string', 'description' => 'JSON array of validation contract assertion strings (set_contract).'],
                'feature_title' => ['type' => 'string', 'description' => 'Feature title (add_feature/update_feature).'],
                'feature_description' => ['type' => 'string', 'description' => 'Feature description (add_feature).'],
                'milestone' => ['type' => 'string', 'description' => 'Milestone grouping label (add_feature).'],
                'feature_status' => ['type' => 'string', 'enum' => Mission::FEATURE_STATUSES, 'description' => 'Feature status (update_feature).'],
                'notes' => ['type' => 'string', 'description' => 'Feature notes (update_feature).'],
                'role' => ['type' => 'string', 'enum' => ['orchestrator', 'worker', 'validator'], 'description' => 'Handoff author role (record_handoff).'],
                'summary' => ['type' => 'string', 'description' => 'Handoff summary (record_handoff).'],
                'completed_work' => ['type' => 'string', 'description' => 'What is now functional (record_handoff).'],
                'undone_work' => ['type' => 'string', 'description' => 'What was deferred or left incomplete (record_handoff).'],
                'execution_log' => ['type' => 'string', 'description' => 'JSON array of {command, exit_code} entries run during the work (record_handoff).'],
                'discovered_issues' => ['type' => 'string', 'description' => 'New bugs/architectural hurdles discovered (record_handoff).'],
                'procedure_adhered' => ['type' => 'boolean', 'description' => 'Whether defined procedures were followed (record_handoff).'],
                'note' => ['type' => 'string', 'description' => 'Context note to persist (add_context_note).'],
                'orchestrator_model' => ['type' => 'string', 'description' => 'Model for planning/set_models.'],
                'worker_model' => ['type' => 'string', 'description' => 'Model for implementation/set_models.'],
                'validator_model' => ['type' => 'string', 'description' => 'Model for verification — prefer a different provider than the worker (set_models).'],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'list' => $this->list(),
            'read' => $this->read((string) ($arguments['mission_name'] ?? '')),
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'set_contract' => $this->setContract($arguments),
            'add_feature' => $this->addFeature($arguments),
            'update_feature' => $this->updateFeature($arguments),
            'start' => $this->transition((string) ($arguments['mission_name'] ?? ''), 'active'),
            'pause' => $this->transition((string) ($arguments['mission_name'] ?? ''), 'paused'),
            'resume' => $this->transition((string) ($arguments['mission_name'] ?? ''), 'active'),
            'complete' => $this->complete((string) ($arguments['mission_name'] ?? '')),
            'fail' => $this->fail((string) ($arguments['mission_name'] ?? '')),
            'advance' => $this->advance((string) ($arguments['mission_name'] ?? '')),
            'record_handoff' => $this->recordHandoff($arguments),
            'add_context_note' => $this->addContextNote($arguments),
            'set_models' => $this->setModels($arguments),
            'list_models' => $this->listModels(),
            default => throw new RuntimeException('Unsupported mission action.'),
        };
    }

    private function listModels(): string
    {
        $openCode = app(OpenCodeService::class);

        if (! $openCode->isAvailable()) {
            return 'opencode is not installed; role models are unavailable.';
        }

        $models = $openCode->models();

        if ($models === []) {
            return 'No opencode models are currently available.';
        }

        return "Available opencode models:\n".implode("\n", array_map(fn (string $m): string => "  - {$m}", $models))
            ."\n\nAssign per role with set_models (validator should differ from worker).";
    }

    private function list(): string
    {
        $missions = Mission::withCount('features')->orderByRaw("FIELD(status,'active','scoping','paused','completed','failed')")->orderBy('name')->get();

        if ($missions->isEmpty()) {
            return 'No missions yet. Use action: create to start one.';
        }

        $lines = $missions->map(fn (Mission $m) => sprintf(
            '[%s] %s — %s | %s | %s tokens | roles: %s',
            strtoupper($m->status),
            $m->name,
            str_replace("\n", ' ', $m->goal),
            $m->progressSummary(),
            number_format((float) $m->spent_tokens),
            collect([
                'plan' => $m->orchestrator_model,
                'work' => $m->worker_model,
                'verify' => $m->validator_model,
            ])->filter()->implode('/') ?: 'unassigned',
        ))->implode("\n");

        return "Missions ({$missions->count()}):\n\n{$lines}";
    }

    private function read(string $name): string
    {
        $mission = $this->findOrFail($name);
        $features = $mission->features;

        $featureLines = $features->isEmpty()
            ? '  (no features yet — use action: add_feature or let the orchestrator scope the plan)'
            : $features->map(fn (MissionFeature $f) => sprintf(
                '  [%d] [%s] %s%s%s',
                $f->sort_order,
                $f->status,
                $f->title,
                $f->milestone ? ' {'.$f->milestone.'}' : '',
                $f->notes ? "\n      Notes: {$f->notes}" : '',
            ))->implode("\n");

        $assertions = collect($mission->contractAssertions())
            ->map(fn ($a, $i): string => is_string($a) ? '  '.($i + 1).". {$a}" : '')
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");
        $contract = $assertions === '' ? '  (empty)' : "\n{$assertions}";

        $handoffs = $mission->handoffs()->take(3)->get();
        $handoffLines = $handoffs->isEmpty()
            ? ''
            : "\n\nRecent handoffs:\n".$handoffs->map(fn (\App\Models\MissionHandoff $h) => sprintf(
                '  (%s) %s',
                $h->role,
                str_replace("\n", ' ', $h->summary),
            ))->implode("\n");

        return <<<OUT
=== Mission: {$mission->name} [{$mission->status}] ===
Goal: {$mission->goal}
Progress: {$mission->progressSummary()}
Budget burn: {$mission->spent_tokens} tokens
Role models — orchestrator: {$mission->orchestrator_model}, worker: {$mission->worker_model}, validator: {$mission->validator_model}

Validation Contract:{$contract}

Features:
{$featureLines}{$handoffs}
OUT;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function create(array $args): string
    {
        $name = trim((string) ($args['name'] ?? ''));
        $goal = trim((string) ($args['goal'] ?? ''));

        if ($name === '' || $goal === '') {
            throw new RuntimeException('create requires name and goal.');
        }

        if (Mission::where('name', $name)->exists()) {
            throw new RuntimeException("Mission '{$name}' already exists.");
        }

        $mission = Mission::create(['name' => $name, 'goal' => $goal, 'status' => 'scoping']);

        $scopingNote = '';

        if ((bool) AgentSetting::get('mission_auto_scope', true)) {
            AdvanceMissionJob::dispatch($mission->id, 'orchestrator');
            $scopingNote = ' An orchestrator scoping run was queued to draft features, milestones, and the Validation Contract.';
        }

        return "Mission '{$name}' created in scoping.{$scopingNote} Next: act as ORCHESTRATOR — decompose the goal into features/milestones and write the Validation Contract (set_contract + add_feature), then start.";
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function update(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));

        $fields = array_filter([
            'name' => isset($args['name']) && trim((string) $args['name']) !== '' ? trim((string) $args['name']) : null,
            'goal' => isset($args['goal']) && trim((string) $args['goal']) !== '' ? trim((string) $args['goal']) : null,
            'status' => $args['status'] ?? null,
        ], fn ($v) => $v !== null);

        if ($fields === []) {
            throw new RuntimeException('No fields to update.');
        }

        if (isset($fields['name']) && $fields['name'] !== $mission->name && Mission::where('name', $fields['name'])->exists()) {
            throw new RuntimeException("A mission named '{$fields['name']}' already exists.");
        }

        $mission->update($fields);

        return "Mission '{$mission->fresh()?->name}' updated.";
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setContract(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));
        $assertions = json_decode((string) ($args['assertions'] ?? ''), true);

        if (! is_array($assertions)) {
            throw new RuntimeException('set_contract requires assertions as a JSON array of strings.');
        }

        $clean = array_values(array_filter(array_map(
            fn ($a) => is_string($a) ? trim($a) : '',
            $assertions,
        ), fn (string $a): bool => $a !== ''));

        if ($clean === []) {
            throw new RuntimeException('The Validation Contract must contain at least one non-empty assertion.');
        }

        $mission->update(['validation_contract' => $clean]);

        return sprintf("Validation Contract saved for '%s' (%d assertions). Every feature will be verified against it before it counts as done.", $mission->name, count($clean));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function addFeature(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));
        $title = trim((string) ($args['feature_title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('feature_title is required.');
        }

        $order = ((int) $mission->features()->max('sort_order')) + 1;
        $milestone = trim((string) ($args['milestone'] ?? ''));

        $feature = $mission->features()->create([
            'title' => $title,
            'description' => $args['feature_description'] ?? null,
            'milestone' => $milestone !== '' ? $milestone : null,
            'status' => 'pending',
            'sort_order' => $order,
        ]);

        $mission->refreshMilestoneCount();

        return "Feature [{$order}] '{$title}' added to mission '{$mission->name}'.";
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateFeature(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));
        $feature = $this->findFeatureOrFail($mission, (string) ($args['feature_title'] ?? ''));

        $fields = array_filter([
            'status' => $args['feature_status'] ?? null,
            'notes' => isset($args['notes']) ? trim((string) $args['notes']) : null,
        ], fn ($v) => $v !== null);

        if ($fields === []) {
            throw new RuntimeException('Nothing to update — provide feature_status and/or notes.');
        }

        $feature->update($fields);
        $mission->refreshMilestoneCount();

        return "Feature '{$feature->title}' updated to [{$feature->status}].";
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function recordHandoff(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));
        $role = (string) ($args['role'] ?? 'worker');

        if (! in_array($role, ['orchestrator', 'worker', 'validator'], true)) {
            throw new RuntimeException("Invalid handoff role '{$role}'.");
        }

        $summary = trim((string) ($args['summary'] ?? ''));

        if ($summary === '') {
            throw new RuntimeException('summary is required for a structured handoff.');
        }

        $feature = isset($args['feature_title'])
            ? $this->findFeatureOrFail($mission, (string) $args['feature_title'])
            : $mission->currentFeature();

        $executionLog = null;

        if (! empty($args['execution_log'])) {
            $decoded = json_decode((string) $args['execution_log'], true);

            if (is_array($decoded)) {
                $executionLog = $decoded;
            }
        }

        $handoff = $mission->handoffs()->create([
            'mission_feature_id' => $feature?->id,
            'role' => $role,
            'summary' => $summary,
            'completed_work' => $args['completed_work'] ?? null,
            'undone_work' => $args['undone_work'] ?? null,
            'execution_log' => $executionLog,
            'discovered_issues' => $args['discovered_issues'] ?? null,
            'procedure_adhered' => array_key_exists('procedure_adhered', $args) ? (bool) $args['procedure_adhered'] : true,
        ]);
        $this->appendContextFromHandoff($mission, $handoff);

        return sprintf(
            "Handoff recorded for mission '%s'%s (role: %s).",
            $mission->name,
            $feature !== null ? " / feature '{$feature->title}'" : '',
            $role,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function addContextNote(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));
        $note = trim((string) ($args['note'] ?? ''));

        if ($note === '') {
            throw new RuntimeException('note is required.');
        }

        $existing = $mission->context_notes ? $mission->context_notes."\n" : '';
        $mission->update(['context_notes' => $existing.'['.now()->toDateTimeString().'] '.$note]);

        return 'Context note added to mission \''.$mission->name.'\'.';
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setModels(array $args): string
    {
        $mission = $this->findOrFail((string) ($args['mission_name'] ?? ''));

        $fields = array_filter([
            'orchestrator_model' => isset($args['orchestrator_model']) ? trim((string) $args['orchestrator_model']) : null,
            'worker_model' => isset($args['worker_model']) ? trim((string) $args['worker_model']) : null,
            'validator_model' => isset($args['validator_model']) ? trim((string) $args['validator_model']) : null,
        ], fn ($v) => $v !== null);

        if ($fields === []) {
            throw new RuntimeException('Provide at least one of orchestrator_model, worker_model, validator_model.');
        }

        if (isset($fields['validator_model'], $fields['worker_model']) && $fields['validator_model'] === $fields['worker_model']) {
            throw new RuntimeException('Prefer a different model/provider for the validator than the worker to avoid shared bias (Droid Whispering).');
        }

        $mission->update($fields);

        return "Role models updated for '{$mission->name}'.";
    }

    private function advance(string $name): string
    {
        $mission = $this->findOrFail($name);

        if ($mission->status === 'scoping') {
            throw new RuntimeException("Mission '{$name}' has no approved plan yet — finish scoping (set_contract/add_feature) then start.");
        }

        if ($mission->status !== 'active') {
            throw new RuntimeException("Mission '{$name}' is {$mission->status} — resume it first.");
        }

        $next = $this->missions->queueNextWorkUnit($mission);

        if ($next === null) {
            return "Mission '{$mission->name}' has no remaining work. Mark complete when validated.";
        }

        $label = $next['role'] === 'worker' ? 'implement' : 'validate';

        return sprintf(
            "Queued %s step: %s feature '%s' on mission '%s'. Serial execution continues via the queue.",
            $next['role'],
            $label,
            $next['feature']->title,
            $mission->name,
        );
    }

    private function transition(string $name, string $status): string
    {
        $mission = $this->findOrFail($name);
        $wasScoping = $mission->status === 'scoping';
        $mission->update(['status' => $status]);

        if ($status === 'active') {
            $mission->update(['started_at' => $mission->started_at ?? now()]);

            if ($wasScoping) {
                if (($contract = count($mission->contractAssertions())) === 0 || $mission->features()->count() === 0) {
                    $mission->update(['status' => 'scoping']);

                    throw new RuntimeException("Mission '{$name}' cannot start without a Validation Contract and at least one feature.");
                }

                return "Mission '{$name}' started — plan approved with {$contract} assertions. Use action: advance to begin serial implementation.";
            }
        }

        return "Mission '{$name}' is now {$mission->status}.";
    }

    private function complete(string $name): string
    {
        $mission = $this->findOrFail($name);

        $unfinished = $mission->features()->whereNotIn('status', ['implemented', 'validated'])->count();

        if ($unfinished > 0) {
            throw new RuntimeException("Mission '{$name}' still has {$unfinished} unfinished feature(s). Complete them or mark them blocked first.");
        }

        $mission->markCompleted();

        return "Mission '{$name}' marked completed. {$mission->progressSummary()}";
    }

    private function fail(string $name): string
    {
        $mission = $this->findOrFail($name);
        $mission->update(['status' => 'failed']);

        return "Mission '{$name}' marked failed.";
    }

    private function findOrFail(string $name): Mission
    {
        if ($name === '') {
            throw new RuntimeException('mission_name is required.');
        }

        $mission = Mission::where('name', $name)->first();

        if ($mission === null) {
            throw new RuntimeException("Mission '{$name}' not found.");
        }

        return $mission;
    }

    private function findFeatureOrFail(Mission $mission, string $title): MissionFeature
    {
        $feature = $mission->features()->where('title', 'like', "%{$title}%")->first();

        if ($feature === null) {
            throw new RuntimeException("Feature '{$title}' not found in mission '{$mission->name}'.");
        }

        return $feature;
    }

    /**
     * Fold handoff findings into persistent mission context so future workers
     * inherit clean, structured knowledge instead of chat baggage.
     */
    private function appendContextFromHandoff(Mission $mission, MissionHandoff $handoff): void
    {
        $parts = [];

        if (trim((string) $handoff->discovered_issues) !== '') {
            $parts[] = 'Issues discovered: '.$handoff->discovered_issues;
        }

        if (trim((string) $handoff->undone_work) !== '') {
            $parts[] = 'Undone: '.$handoff->undone_work;
        }

        if ($parts === []) {
            return;
        }

        $entry = '['.now()->toDateTimeString()."] {$handoff->role} handoff — ".implode(' | ', $parts);
        $existing = $mission->context_notes ? $mission->context_notes."\n" : '';
        $mission->update(['context_notes' => $existing.$entry]);
    }
}
