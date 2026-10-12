<?php

namespace App\Services\Agent;

use App\Models\AgentMessage;
use App\Models\AgentRoleProfile;
use App\Models\Mission;
use App\Models\MissionFeature;
use Illuminate\Support\Collection;

/**
 * Agent-to-agent messaging and mission choreography.
 *
 * The orchestrator is the office's "team lead": it opens a mission with a
 * kickoff huddle broadcast to every active role agent, then hands features to
 * the most appropriate specialist. Every exchange is persisted as an
 * {@see AgentMessage}, which the presence surface reads back to decide whether
 * an agent is in a meeting or heads-down at a desk.
 */
class AgentOrchestrator
{
    public function __construct(
        private readonly RoleProfileService $roles,
    ) {}

    /**
     * Open a mission with a kickoff huddle: one message from the orchestrator
     * to every active role agent. Returns the number of participants.
     */
    public function startHuddle(Mission $mission): int
    {
        $threadKey = $this->threadKey($mission);
        $participants = $this->activeProfiles();

        foreach ($participants as $profile) {
            $this->post(
                from: AgentMessage::FROM_ORCHESTRATOR,
                to: $profile->slug,
                kind: 'kickoff',
                body: sprintf(
                    'Kickoff for mission "%s". Goal: %s. We are huddling to scope the plan — meet at the table.',
                    $mission->name,
                    $mission->goal,
                ),
                missionId: $mission->id,
                threadKey: $threadKey,
            );
        }

        return $participants->count();
    }

    /**
     * Hand a mission feature to the best-fit role agent. Returns the created
     * assignment, or null when the feature has no mission.
     */
    public function assignFeature(MissionFeature $feature, string $role = 'worker'): ?AgentMessage
    {
        $mission = $feature->mission;

        if ($mission === null) {
            return null;
        }

        $slug = $this->roleSlugForFeature($feature);

        return $this->post(
            from: AgentMessage::FROM_ORCHESTRATOR,
            to: $slug,
            kind: 'assignment',
            body: sprintf(
                '%s assignment: feature [%d] "%s". %s',
                ucfirst($role),
                $feature->sort_order,
                $feature->title,
                trim((string) $feature->description) !== '' ? (string) $feature->description : 'Head to your desk and begin.',
            ),
            missionId: $mission->id,
            threadKey: $this->threadKey($mission),
        );
    }

    /**
     * Persist a directed agent-to-agent message.
     */
    public function post(
        string $from,
        ?string $to,
        string $kind,
        string $body,
        ?int $missionId = null,
        ?string $threadKey = null,
    ): AgentMessage {
        return AgentMessage::query()->create([
            'thread_key' => $threadKey,
            'mission_id' => $missionId,
            'from_agent' => $from,
            'to_agent' => $to,
            'kind' => $kind,
            'body' => $body,
        ]);
    }

    /**
     * The role slug a mission feature should be handed to, inferred from its
     * title and description. Falls back to the general operator.
     */
    public function roleSlugForFeature(MissionFeature $feature): string
    {
        $text = trim($feature->title.' '.(string) $feature->description);
        $slug = $this->roles->slugForText($text);

        if ($slug !== null && $this->activeProfiles()->contains('slug', $slug)) {
            return $slug;
        }

        return $this->defaultSlug();
    }

    private function defaultSlug(): string
    {
        $profiles = $this->activeProfiles();

        return $profiles->firstWhere('slug', 'general_operator')->slug
            ?? $profiles->first()->slug
            ?? 'general_operator';
    }

    /**
     * @return Collection<int, AgentRoleProfile>
     */
    private function activeProfiles(): Collection
    {
        return $this->roles->activeProfiles();
    }

    private function threadKey(Mission $mission): string
    {
        return 'mission:'.$mission->id;
    }
}
