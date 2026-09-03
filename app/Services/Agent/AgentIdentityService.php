<?php

namespace App\Services\Agent;

use App\Models\AgentMemory;
use App\Models\Conversation;
use App\Models\TaskLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AgentIdentityService
{
    /**
     * @return Collection<int, AgentMemory>
     */
    public function relevantMemories(Conversation $conversation, string $userMessage): Collection
    {
        $keywords = $this->keywords($userMessage);

        return AgentMemory::query()
            ->active()
            ->where(function ($query) use ($conversation, $keywords): void {
                $query
                    ->whereIn('scope', ['global', 'environment', 'user'])
                    ->orWhere(function ($subjectQuery) use ($conversation): void {
                        $subjectQuery
                            ->where('subject_type', Conversation::class)
                            ->where('subject_id', $conversation->id);
                    });

                if ($keywords !== []) {
                    $query->orWhere(function ($keywordQuery) use ($keywords): void {
                        foreach ($keywords as $keyword) {
                            $keywordQuery
                                ->orWhere('key', 'like', "%{$keyword}%")
                                ->orWhere('value', 'like', "%{$keyword}%")
                                ->orWhere('category', 'like', "%{$keyword}%");
                        }
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

    public function buildPromptContext(Conversation $conversation, string $userMessage): string
    {
        $memories = $this->relevantMemories($conversation, $userMessage);
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

        $sections[] = 'Identity rule: behave as a continuing employee-agent. Reuse durable preferences and workflow memory, but correct stale memory when the current environment proves it wrong.';

        return "\n\n".implode("\n", $sections);
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
