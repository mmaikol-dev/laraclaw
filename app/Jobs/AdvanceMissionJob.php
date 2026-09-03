<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Mission;
use App\Models\MissionFeature;
use App\Services\Agent\AgentService;
use App\Services\Agent\MissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Serial mission executor. Each job handles exactly one work unit so features
 * never execute in parallel and the codebase stays functional between steps:
 *
 * - role "worker"/"validator": implement or adversarially verify one feature.
 * - role "orchestrator" with featureId 0: initial scoping run for a new mission.
 * - role "orchestrator" with featureId > 0: self-healing rescope of a blocked feature.
 *
 * After a unit finishes, the next unit is queued automatically until the
 * mission has no remaining work, keeping serial execution alive on the queue.
 */
class AdvanceMissionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $missionId,
        public string $role,
        public int $featureId = 0,
    ) {}

    public function handle(AgentService $agent, MissionService $missions): void
    {
        $mission = Mission::find($this->missionId);

        if ($mission === null || ! in_array($mission->status, ['active', 'scoping'], true)) {
            return;
        }

        $feature = $this->featureId !== 0 ? MissionFeature::find($this->featureId) : null;

        if ($this->featureId !== 0 && $feature === null) {
            return;
        }

        $prompt = $this->buildPrompt($missions, $mission, $feature);

        if ($prompt === null) {
            return;
        }

        $conversation = $this->ensureConversation($mission);

        try {
            $message = $agent->run($conversation, $prompt, "conversation.{$conversation->id}");
        } catch (\Throwable) {
            // The chain stops here; a failed unit must not silently cascade.
            $this->releaseFeature();

            return;
        }

        $content = trim((string) $message->content);

        if (str_starts_with($content, 'LaraClaw could not complete')) {
            // Degraded response (e.g. LLM provider outage saved as message text).
            // Treat as failure: release the claim so a later fire can retry,
            // and stop chaining instead of burning through remaining features.
            Log::warning('Mission unit aborted: degraded agent response.', [
                'mission_id' => $this->missionId,
                'role' => $this->role,
                'feature_id' => $this->featureId,
            ]);
            $this->releaseFeature();

            return;
        }

        $mission->increment('spent_tokens', (int) $message->prompt_tokens + (int) $message->completion_tokens);

        $this->finalizeUnit($content);
        $this->chainNext($missions);
    }

    /**
     * Deterministic status transitions after a successful unit. The LLM is
     * expected to self-report via the mission tool, but a missing or malformed
     * self-report must never wedge the serial queue.
     */
    private function finalizeUnit(string $content): void
    {
        if ($this->featureId === 0) {
            return;
        }

        $feature = MissionFeature::find($this->featureId);

        if ($feature === null) {
            return;
        }

        if ($this->role === 'worker' && in_array($feature->status, ['in_progress', 'pending'], true)) {
            // Worker finished cleanly: the implementation now awaits validation,
            // whether or not the agent remembered to call update_feature itself.
            $feature->update(['status' => 'implemented']);

            return;
        }

        if ($this->role === 'validator' && str_contains($content, 'BLOCKED') && $feature->status !== 'blocked') {
            // Explicit adversarial rejection: route to the self-healing rescope.
            $feature->update(['status' => 'blocked']);
        }
    }

    /**
     * Queue whatever comes after this work unit. Public so tests can verify
     * chaining without executing an agent run.
     */
    public function chainNext(MissionService $missions): void
    {
        $mission = Mission::find($this->missionId)?->fresh();

        if ($mission === null || $mission->status !== 'active') {
            return;
        }

        if ($this->role === 'validator' && $this->featureId !== 0) {
            $feature = MissionFeature::find($this->featureId);

            if ($feature !== null && $feature->status === 'blocked') {
                // Self-healing loop: hand the failure to the orchestrator.
                AdvanceMissionJob::dispatch($this->missionId, 'orchestrator', $this->featureId);

                return;
            }
        }

        if ($this->role === 'orchestrator' && $mission->status === 'scoping') {
            // Scoping just ran; wait for human plan approval via action: start.
            return;
        }

        $missions->queueNextWorkUnit($mission);
    }

    /**
     * Undo an in_progress claim left behind by a crashed unit so the feature
     * becomes claimable again instead of deadlocking the serial queue.
     */
    private function releaseFeature(): void
    {
        if ($this->featureId === 0) {
            return;
        }

        $feature = MissionFeature::find($this->featureId);

        if ($feature === null || $feature->status !== 'in_progress') {
            return;
        }

        // A crashed worker did no durable work (back to pending for retry);
        // a crashed validator leaves the implementation intact.
        $feature->update(['status' => $this->role === 'validator' ? 'implemented' : 'pending']);
    }

    /**
     * @return string|null null when this unit is stale and should be skipped.
     */
    private function buildPrompt(MissionService $missions, Mission $mission, ?MissionFeature $feature): ?string
    {
        if ($this->role === 'orchestrator') {
            if ($feature !== null) {
                if ($feature->status !== 'blocked') {
                    return null;
                }

                return $missions->orchestratorRescopePrompt($mission, $feature);
            }

            if (! $missions->needsScoping($mission)) {
                return null;
            }

            return $missions->orchestratorScopingPrompt($mission);
        }

        if ($feature === null) {
            return null;
        }

        return $this->role === 'validator'
            ? $missions->validatorPrompt($mission, $feature)
            : $missions->workerPrompt($mission, $feature);
    }

    private function ensureConversation(Mission $mission): Conversation
    {
        $conversation = $mission->conversation_id
            ? $mission->conversation
            : null;

        if ($conversation === null) {
            $conversation = Conversation::create(['title' => "Mission: {$mission->name}"]);
        }

        if ($mission->conversation_id !== $conversation->id) {
            $mission->update(['conversation_id' => $conversation->id]);
        }

        return $conversation;
    }
}
