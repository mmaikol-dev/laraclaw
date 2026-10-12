<?php

namespace App\Services\Agent;

use App\Models\AgentMemory;
use App\Models\AgentRoleProfile;
use App\Models\Conversation;
use App\Models\TaskLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AgentIdentityService
{
    public function __construct(private readonly OutcomeRecorder $outcomes) {}

    /**
     * @return Collection<int, AgentMemory>
     */
    public function relevantMemories(Conversation $conversation, string $userMessage, ?AgentRoleProfile $roleProfile = null): Collection
    {
        $keywords = $this->keywords($userMessage);
        $roleProfileId = ($roleProfile ?? $conversation->roleProfile)?->id;

        return AgentMemory::query()
            ->active()
            ->where(function ($query) use ($conversation, $roleProfileId, $keywords): void {
                $query
                    ->whereIn('scope', ['global', 'environment', 'user'])
                    ->orWhere(function ($subjectQuery) use ($conversation): void {
                        $subjectQuery
                            ->where('subject_type', Conversation::class)
                            ->where('subject_id', $conversation->id);
                    });

                // Experience earned by the employee handling this request, kept
                // separate from every other employee's.
                if ($roleProfileId !== null) {
                    $query->orWhere(function ($roleQuery) use ($roleProfileId): void {
                        $roleQuery
                            ->where('scope', 'role')
                            ->where('subject_type', AgentRoleProfile::class)
                            ->where('subject_id', $roleProfileId);
                    });
                }

                if ($keywords !== []) {
                    $query->orWhere(function ($keywordQuery) use ($keywords, $roleProfileId): void {
                        $keywordQuery->where(function ($matches) use ($keywords): void {
                            foreach ($keywords as $keyword) {
                                $matches
                                    ->orWhere('key', 'like', "%{$keyword}%")
                                    ->orWhere('value', 'like', "%{$keyword}%")
                                    ->orWhere('category', 'like', "%{$keyword}%");
                            }
                        });

                        // Keyword matching must not become a side channel: another
                        // employee's role memory stays invisible even when the words match.
                        $keywordQuery->where(function ($scopeQuery) use ($roleProfileId): void {
                            $scopeQuery->where('scope', '!=', 'role');

                            if ($roleProfileId !== null) {
                                $scopeQuery->orWhere(function ($ownRoleQuery) use ($roleProfileId): void {
                                    $ownRoleQuery
                                        ->where('subject_type', AgentRoleProfile::class)
                                        ->where('subject_id', $roleProfileId);
                                });
                            }
                        });
                    });
                }
            })
            ->orderByDesc('last_observed_at')
            ->orderBy('category')
            ->limit(16)
            ->get();
    }

    public function rememberRunOutcome(Conversation $conversation, string $summary, string $category = 'workflow'): void
    {
        $key = 'conversation.'.$conversation->id.'.last_outcome';

        AgentMemory::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => Str::limit(Str::squish($summary), 1000, ''),
                'category' => $category,
                'scope' => 'conversation',
                'subject_type' => Conversation::class,
                'subject_id' => $conversation->id,
                'source' => 'agent-run',
                'confidence' => 0.8,
                'tags' => ['continuity', 'outcome'],
                'last_observed_at' => now(),
            ],
        );
    }

    public function buildPromptContext(Conversation $conversation, string $userMessage, ?AgentRoleProfile $roleProfile = null): string
    {
        $roleProfile ??= $conversation->roleProfile;

        $memories = $this->relevantMemories($conversation, $userMessage, $roleProfile);
        $recentFailures = TaskLog::query()
            ->where('status', 'error')
            ->latest('id')
            ->limit(5)
            ->get();

        $sections = ['=== Persistent Identity & Memory ==='];

        if ($memories->isEmpty()) {
            $sections[] = 'No durable memories matched this request yet. Store durable facts, preferences, workflow decisions, and project context when discovered.';
        } else {
            $sections[] = 'Durable memories to consider:';
            foreach ($memories as $memory) {
                $scope = $memory->scope ?: 'global';
                $sections[] = "- [{$scope}/{$memory->category}] {$memory->key}: ".Str::limit(Str::squish($memory->value), 220);
            }
        }

        if ($recentFailures->isNotEmpty()) {
            $sections[] = 'Recent operational failures to avoid repeating:';
            foreach ($recentFailures as $failure) {
                $sections[] = "- {$failure->tool_name}: ".Str::limit((string) $failure->error_message, 180);
            }
        }

        $sections = array_merge($sections, $this->outcomeContext($conversation, $userMessage, $roleProfile));

        $sections[] = 'Identity rule: behave as a continuing employee-agent. Reuse durable preferences and workflow memory, but correct stale memory when the current environment proves it wrong.';

        return "\n\n".implode("\n", $sections);
    }

    /**
     * Surface what this employee has actually done before, and whether it held up.
     *
     * Verified failures are listed first and an unverified run is labelled as
     * such, so a confident summary is never mistaken for proof of correctness.
     *
     * @return array<int, string>
     */
    private function outcomeContext(Conversation $conversation, string $userMessage, ?AgentRoleProfile $roleProfile = null): array
    {
        $outcomes = $this->outcomes->relevantOutcomes($roleProfile?->id, $userMessage);

        if ($outcomes->isEmpty()) {
            return [];
        }

        $sections = ['Your own record on similar work (only verified runs count as evidence):'];

        foreach ($outcomes as $outcome) {
            $label = match ($outcome->verdict) {
                'passed' => 'VERIFIED OK',
                'failed' => 'VERIFIED FAILED',
                default => 'NOT VERIFIED',
            };

            $line = "- [{$label}] ".Str::limit((string) $outcome->intent, 140);

            if ($outcome->verdict === 'failed' && $outcome->failure_reason) {
                $line .= ' — do not repeat this approach; it failed because: '.Str::limit($outcome->failure_reason, 180);
            }

            $sections[] = $line;
        }

        $reliability = $this->outcomes->reliabilitySummary($roleProfile?->id);
        $sections[] = sprintf(
            'Your track record so far: %d verified pass, %d verified fail, %d unverified. Treat unverified runs as unproven.',
            $reliability['verified_passed'],
            $reliability['verified_failed'],
            $reliability['unverified'],
        );

        return $sections;
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $message): array
    {
        return collect(preg_split('/[^a-z0-9_\/.-]+/i', strtolower($message)) ?: [])
            ->map(fn (string $word): string => trim($word))
            ->filter(fn (string $word): bool => strlen($word) >= 4)
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }
}
