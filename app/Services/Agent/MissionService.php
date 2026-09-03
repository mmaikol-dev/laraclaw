<?php

namespace App\Services\Agent;

use App\Jobs\AdvanceMissionJob;
use App\Models\AgentSetting;
use App\Models\Mission;
use App\Models\MissionFeature;
use App\Models\MissionHandoff;
use RuntimeException;

/**
 * Prompt-driven orchestration for the Missions paradigm.
 *
 * Roles (Droid Whispering): Orchestrator plans, Workers implement serially
 * with clean-slate context, Validators verify adversarially against the
 * pre-defined Validation Contract. All logic is delivered as prompts so the
 * system inherits model improvements without hard-coded state machines.
 */
class MissionService
{
    /**
     * Safety guard: a feature worked this many times without passing
     * validation is auto-blocked instead of looping forever.
     */
    public const DEFAULT_MAX_FEATURE_ATTEMPTS = 6;

    /**
     * Orchestrator scoping prompt: decompose the goal into features grouped by
     * milestones plus a Validation Contract, before any implementation begins.
     */
    public function orchestratorScopingPrompt(Mission $mission): string
    {
        $model = $mission->orchestrator_model ?: '(default)';

        return <<<PROMPT
You are the ORCHESTRATOR for the mission "{$mission->name}" (model: {$model}).

GOAL:
{$mission->goal}

Produce the mission plan BEFORE any code is written. Respond with exactly three sections:

1. FEATURES — an atomic, ordered breakdown of the work. For each feature output one line:
   FEATURE <n>: <title> :: <one-sentence description> :: <milestone name>

2. MILESTONES — logical checkpoints grouping features into verifiable stages.

3. VALIDATION CONTRACT — the assertions that define "done", written now, independently of any implementation. Each assertion must be objectively checkable (tests, commands, observable behaviour). Format each on its own line prefixed with "ASSERT:".

Rules:
- Features are implemented SERIALLY; order them so the codebase stays functional after every feature.
- Aim for granular features; hundreds of small assertions are better than a few vague ones.
- Do not implement anything yet. Planning only.

After writing the plan, record it using the mission tool:
- action: set_contract with the assertion list (JSON array of strings)
- action: add_feature for every feature in order (pass milestone)
Then report the plan to the user and mark the mission active via action: start when the user approves.
PROMPT;
    }

    /**
     * Self-healing rescope prompt: after a validator blocks a feature, the
     * orchestrator turns its findings into follow-up features or a retry plan
     * so the mission pulls itself back on track without human intervention.
     */
    public function orchestratorRescopePrompt(Mission $mission, MissionFeature $feature): string
    {
        if ($feature->mission_id !== $mission->id) {
            throw new RuntimeException('Feature does not belong to this mission.');
        }

        $findings = $feature->handoffs()
            ->where('role', 'validator')
            ->take(3)
            ->get()
            ->map(fn (MissionHandoff $h): string => '  - '.str_replace("\n", ' ', (string) $h->summary))
            ->implode("\n");

        if ($findings === '') {
            $findings = '  - (no recorded validator findings)';
        }

        $assertions = collect($mission->assertionsForFeature($feature))
            ->map(fn (string $a): string => "  - {$a}")
            ->implode("\n");

        return <<<PROMPT
You are the ORCHESTRATOR for the mission "{$mission->name}". The mission has drifted and you are pulling it back on track at a milestone boundary.

BLOCKED FEATURE [{$feature->sort_order}]: {$feature->title}
Attempts so far: {$feature->attempts}
Notes: {$feature->notes}

VALIDATOR FINDINGS (adversarial, treat as ground truth):
{$findings}

Relevant Validation Contract assertions:
{$assertions}

RESCOPE PROCEDURE:
1. Analyse the findings against the contract assertions above.
2. Decompose the fixes into small follow-up features and record them with the mission tool (action: add_feature). Give them clear titles prefixed with "Follow-up:" so their origin is traceable.
3. If the original feature is still worth retrying with better instructions, set it back to pending via action: update_feature with notes describing what must change. Otherwise leave it blocked; the follow-up features carry the fix.
4. Record an orchestrator handoff (action: record_handoff, role: orchestrator) summarising the rescope decision.

Do not implement anything yourself. Planning and rescoping only.
PROMPT;
    }

    /**
     * Worker prompt: clean-context implementation of exactly one feature,
     * ending in a git commit and a structured handoff.
     */
    public function workerPrompt(Mission $mission, MissionFeature $feature): string
    {
        if ($feature->mission_id !== $mission->id) {
            throw new RuntimeException('Feature does not belong to this mission.');
        }

        $model = $mission->worker_model
            ? "Use the opencode tool with model: {$mission->worker_model}."
            : 'Pick the strongest coding-capable model from the opencode list_models output yourself and run with it. NEVER stop to ask the user which model — you are running autonomously.';
        $assertions = collect($mission->assertionsForFeature($feature))
            ->map(fn (string $a): string => "  - {$a}")
            ->implode("\n");
        $context = $mission->context_notes ? "\nMISSION CONTEXT from previous handoffs:\n{$mission->context_notes}\n" : '';
        $description = $feature->description ? "\nDescription: {$feature->description}" : '';

        return <<<PROMPT
You are the WORKER for mission "{$mission->name}". You have a CLEAN SLATE: implement only the feature below, ignoring unrelated improvements.

FEATURE [{$feature->sort_order}] ({$feature->status}): {$feature->title}{$description}
Milestone: {$feature->milestone}{$context}
Relevant Validation Contract assertions:
{$assertions}

DELEGATION RULES:
- {$model}
- All coding must go through opencode (long-running tasks are supported).
- Serial execution: do not start any other feature.

COMPLETION REQUIREMENTS:
1. Implement the feature.
2. Verify it against the assertions listed above.
3. Commit the working code with git.
4. Record a structured handoff using the mission tool (action: record_handoff, role: worker) containing: summary, completed_work, undone_work, execution_log (command + exit_code entries), discovered_issues, procedure_adhered.

EXECUTION DISCIPLINE (critical):
- Do NOT reply with plans, readiness announcements, or questions. A response without tool calls is a failed step.
- In your FIRST tool call, actually invoke opencode action run to implement the feature — announcing that you are "ready to begin" is not progress.
- Keep issuing tool calls until implementation is committed and the handoff is recorded; only then write a one-paragraph closing summary.
5. Update the feature status via action: update_feature (implemented or blocked).

If you discover architectural issues affecting later features, include them in discovered_issues so the orchestrator can rescope at the next milestone boundary.
PROMPT;
    }

    /**
     * Validator prompt: adversarial verification by a fresh agent with no
     * investment in the Worker's implementation. Two tiers: scrutiny
     * validation (tests, lint, review) and user-testing validation (driving
     * the live application like a QA engineer).
     */
    public function validatorPrompt(Mission $mission, MissionFeature $feature): string
    {
        if ($feature->mission_id !== $mission->id) {
            throw new RuntimeException('Feature does not belong to this mission.');
        }

        $model = $mission->validator_model ? "Use the opencode tool with model: {$mission->validator_model} (deliberately different from the worker's model to avoid shared bias)." : 'Ask the user which opencode model to use for validation (prefer a different provider/family than the worker).';
        $assertions = collect($mission->contractAssertions())
            ->map(fn (string $a): string => "  - {$a}")
            ->implode("\n");
        $description = $feature->description ? "\nDescription: {$feature->description}" : '';

        return <<<PROMPT
You are the VALIDATOR for mission "{$mission->name}". You are ADVERSARIAL BY DESIGN: you did not write this code and you are not invested in it working. Your job is to find flaws.

Feature under review: {$feature->title}{$description}

VALIDATION CONTRACT (the objective source of truth — the code must satisfy these):
{$assertions}

PROCEDURE — run both validation tiers:

TIER 1 — SCRUTINY VALIDATION:
- {$model}
- Run the full test suite, linting, and static analysis where configured.
- Review the recent diff for logic bugs, security issues, and drift from the contract.

TIER 2 — USER-TESTING VALIDATION (computer use):
- Act as a QA engineer testing the real application, not just reading code.
- Launch the application (background long-running servers with nohup), then use the browser/web tools to exercise the actual user flows affected by this feature: click buttons, fill forms, submit data, verify observable outcomes.
- Confirm behaviour matches each contract assertion from the user's perspective, end to end.

OUTPUT:
- If ANY assertion fails or you find defects: update the feature status to blocked via the mission tool (action: update_feature) with notes describing each failure, then record_handoff with role: validator listing every discrepancy. Failures are expected on first attempt — be precise so follow-up features can be created. Your final reply MUST contain the word BLOCKED when you blocked the feature.
- Only when every assertion passes both tiers: mark the feature validated (action: update_feature) and record_handoff with role: validator confirming which assertions were verified and how. Your final reply must NOT contain the word BLOCKED in that case.
Never confirm your own assumptions — run the commands, click the flows, and observe real behaviour.
PROMPT;
    }

    /**
     * Whether a mission still needs its initial orchestrator scoping run.
     */
    public function needsScoping(Mission $mission): bool
    {
        return $mission->status === 'scoping'
            && $mission->contractAssertions() === []
            && $mission->features()->count() === 0;
    }

    /**
     * The next serial unit of work for a mission: validate the feature that is
     * currently implemented but unvalidated, otherwise start the next pending
     * feature. Returns null when nothing is left.
     *
     * Features that exhausted their attempt budget are auto-blocked with a
     * context note so the chain can never loop forever.
     */
    public function nextAction(Mission $mission): ?array
    {
        if ($mission->status !== 'active') {
            return null;
        }

        while (true) {
            $awaitingValidation = $mission->features()->where('status', 'implemented')->orderBy('sort_order')->first();
            $candidate = $awaitingValidation !== null
                ? ['role' => 'validator', 'feature' => $awaitingValidation]
                : null;

            if ($candidate === null) {
                $pending = $mission->nextPendingFeature();
                $candidate = $pending !== null ? ['role' => 'worker', 'feature' => $pending] : null;
            }

            if ($candidate === null) {
                return null;
            }

            if ($candidate['feature']->attempts < $this->maxFeatureAttempts()) {
                return $candidate;
            }

            $this->exhaustFeature($candidate['feature']);
        }
    }

    /**
     * Claim and queue the next serial work unit for autonomous execution.
     *
     * Marks the claimed feature, dispatches AdvanceMissionJob, and marks the
     * mission completed when no work remains. Returns the queued unit or null.
     *
     * @return array{role: string, feature: MissionFeature}|null
     */
    public function queueNextWorkUnit(Mission $mission): ?array
    {
        $next = $this->nextAction($mission);

        if ($next === null) {
            $this->maybeComplete($mission);

            return null;
        }

        $mission->update(['current_feature_id' => $next['feature']->id]);

        if ($next['role'] === 'worker') {
            $next['feature']->update(['status' => 'in_progress']);
        }

        $next['feature']->markAttempted();

        AdvanceMissionJob::dispatch($mission->id, $next['role'], $next['feature']->id);

        return $next;
    }

    public function maxFeatureAttempts(): int
    {
        return max(1, (int) AgentSetting::get('mission_max_feature_attempts', self::DEFAULT_MAX_FEATURE_ATTEMPTS));
    }

    private function exhaustFeature(MissionFeature $feature): void
    {
        $notes = trim((string) $feature->notes."\n".sprintf(
            'Auto-blocked after %d attempts without passing validation. Orchestrator rescope required.',
            $feature->attempts,
        ));

        $feature->update(['status' => 'blocked', 'notes' => $notes]);
    }

    /**
     * A mission counts as complete once it has features and none remain
     * pending, in-progress, or blocked.
     */
    private function maybeComplete(Mission $mission): void
    {
        $total = $mission->features()->count();

        if ($total === 0) {
            return;
        }

        $unfinished = $mission->features()
            ->whereIn('status', ['pending', 'in_progress', 'blocked'])
            ->count();

        if ($unfinished > 0) {
            return;
        }

        $mission->markCompleted();
    }
}
