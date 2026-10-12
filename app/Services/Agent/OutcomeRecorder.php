<?php

namespace App\Services\Agent;

use App\Models\AgentRunOutcome;
use App\Models\Conversation;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Records what an agent run actually did and whether it worked.
 *
 * A run's verdict is only ever "verified" when an independent verifier produced
 * it. Plain chat runs are recorded as unverified, so a confident summary can
 * never be mistaken downstream for evidence that the work is correct.
 */
class OutcomeRecorder
{
    /**
     * Record the terminal outcome of a conversational run.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     */
    public function recordChatRun(Conversation $conversation, string $intent, string $status, string $summary, array $toolCalls = [], ?string $failureReason = null): ?AgentRunOutcome
    {
        // Task steps run as ordinary agent runs. Recording each one as an
        // employee outcome would bury the real record under boilerplate
        // "executing one step of the task" prompts; the task's own terminal
        // outcome is the meaningful record.
        if ($conversation->task()->exists()) {
            return null;
        }

        return $this->record([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'role_profile_id' => $conversation->role_profile_id,
            'source' => AgentRunOutcome::SOURCE_CHAT,
            'subject_type' => Conversation::class,
            'subject_id' => $conversation->id,
            'intent' => $intent,
            'status' => $status,
            'verdict' => AgentRunOutcome::VERDICT_UNVERIFIED,
            'verdict_source' => 'none',
            'failure_reason' => $failureReason,
            'tools_used' => $this->toolNames($toolCalls),
            'summary' => $this->truncate($summary),
        ]);
    }

    /**
     * Record the terminal outcome of an autonomous task, carrying the verifier's
     * authoritative result rather than the worker's own claim of success.
     *
     * Tool usage is deliberately left null for tasks: tool invocations are only
     * tracked per conversation, so attributing them to a specific task would
     * record a guess as though it were evidence.
     *
     * @param  array<string, mixed>  $result
     */
    public function recordTask(Task $task, string $status, ?array $result = null, ?string $failureReason = null): AgentRunOutcome
    {
        $verdict = $this->verdictFromResult($result, $status);
        $unmet = $this->unmetCriteria($result);

        $failureReason ??= $this->describeFailure($task, $unmet, $result['reason'] ?? null);

        return $this->record([
            'conversation_id' => $task->conversation_id,
            'user_id' => $task->user_id,
            'role_profile_id' => $task->conversation?->role_profile_id,
            'source' => AgentRunOutcome::SOURCE_TASK,
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'intent' => $task->goal,
            'status' => $status,
            'verdict' => $verdict,
            'verdict_source' => $result === null ? 'none' : 'verifier',
            'model' => $task->model,
            'failure_type' => $this->failureTypeFromResult($result) ?? $task->failure_type,
            'failure_reason' => $this->truncate($failureReason ?? $task->failure_reason),
            'summary' => $this->truncate($task->last_result),
        ]);
    }

    /**
     * Record the terminal outcome of a mission.
     *
     * @param  array<string, mixed>  $result
     */
    public function recordMission(Model $mission, string $status, ?array $result = null, ?string $failureReason = null): AgentRunOutcome
    {
        $verdict = $this->verdictFromResult($result, $status);

        return $this->record([
            'conversation_id' => $mission->conversation_id ?? null,
            'source' => AgentRunOutcome::SOURCE_MISSION,
            'subject_type' => $mission::class,
            'subject_id' => $mission->id,
            'intent' => $mission->goal ?? $mission->name,
            'status' => $status,
            'verdict' => $verdict,
            'verdict_source' => $result === null ? 'none' : 'verifier',
            'model' => $mission->worker_model ?? null,
            'failure_reason' => $this->truncate($failureReason ?? ($result['reason'] ?? null)),
            'summary' => $this->truncate($mission->context_notes ?? ''),
        ]);
    }

    /**
     * Retrieve past outcomes relevant to a new request so the agent can avoid
     * repeating approaches that already failed.
     *
     * Scoped to a single employee's own experience, and verified failures are
     * ranked first because they carry the learning signal.
     *
     * @return Collection<int, AgentRunOutcome>
     */
    public function relevantOutcomes(?int $roleProfileId, string $query, int $limit = 5): Collection
    {
        $keywords = $this->keywords($query);

        return AgentRunOutcome::query()
            ->where('role_profile_id', $roleProfileId)
            ->latest('id')
            ->limit(60)
            ->get()
            ->filter(fn (AgentRunOutcome $outcome): bool => $this->matchesKeywords($outcome, $keywords))
            ->take($limit)
            ->sortBy(fn (AgentRunOutcome $outcome): int => $outcome->verdict === AgentRunOutcome::VERDICT_FAILED ? 0 : 1)
            ->values();
    }

    /**
     * Summarise how the employee has actually been doing, verified work only.
     *
     * @return array{verified_passed: int, verified_failed: int, unverified: int}
     */
    public function reliabilitySummary(?int $roleProfileId): array
    {
        $base = AgentRunOutcome::query()->where('role_profile_id', $roleProfileId);

        return [
            'verified_passed' => (clone $base)->where('verdict', AgentRunOutcome::VERDICT_PASSED)->count(),
            'verified_failed' => (clone $base)->where('verdict', AgentRunOutcome::VERDICT_FAILED)->count(),
            'unverified' => (clone $base)->where('verdict', AgentRunOutcome::VERDICT_UNVERIFIED)->count(),
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function matchesKeywords(AgentRunOutcome $outcome, array $keywords): bool
    {
        if ($keywords === []) {
            return true;
        }

        $haystack = mb_strtolower(trim(implode(' ', array_filter([
            $outcome->intent,
            $outcome->failure_reason,
            $outcome->failure_type,
        ]))));

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        $keywords = array_values(array_unique(array_filter($words, static fn (string $word): bool => mb_strlen($word) > 3)));

        return array_slice($keywords, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(array $attributes): AgentRunOutcome
    {
        return AgentRunOutcome::query()->create($attributes);
    }

    /**
     * Derive the verdict from a verifier result, never from a status flag.
     *
     * @param  array<string, mixed>|null  $result
     */
    private function verdictFromResult(?array $result, string $status): string
    {
        if ($result === null) {
            return AgentRunOutcome::VERDICT_UNVERIFIED;
        }

        return ($result['status'] ?? null) === 'passed'
            ? AgentRunOutcome::VERDICT_PASSED
            : AgentRunOutcome::VERDICT_FAILED;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function failureTypeFromResult(?array $result): ?string
    {
        if ($result === null || ($result['status'] ?? null) === 'passed') {
            return null;
        }

        $unmet = $this->unmetCriteria($result);

        return $unmet === [] ? 'unverified_incomplete' : 'criteria_unmet';
    }

    /**
     * Build a self-describing failure reason.
     *
     * The verifier names the check that failed ("Required files") but drops the
     * criterion it failed against, so the task's own acceptance criteria are
     * carried through to keep the record actionable when it is read back later.
     *
     * @param  array<int, string>  $unmet
     */
    private function describeFailure(Task $task, array $unmet, ?string $reason): ?string
    {
        if ($unmet === [] && $reason === null) {
            return null;
        }

        $parts = [];

        if ($unmet !== []) {
            $parts[] = 'Unmet checks: '.implode('; ', $unmet);
        }

        if ($reason !== null && $reason !== '' && ! $this->reasonRepeatsChecks($reason, $unmet)) {
            $parts[] = $reason;
        }

        $criteria = $this->extractList($task->acceptance_criteria);

        if ($criteria !== []) {
            $parts[] = 'Required to have passed: '.implode('; ', $criteria);
        }

        return implode('. ', $parts);
    }

    /**
     * The verifier's own reason is usually just its unmet-check list restated.
     *
     * @param  array<int, string>  $unmet
     */
    private function reasonRepeatsChecks(string $reason, array $unmet): bool
    {
        if ($unmet === []) {
            return false;
        }

        foreach ($unmet as $check) {
            if ($check !== '' && str_contains($reason, $check)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unmet acceptance criteria, tolerating either key the verifier may use.
     *
     * @param  array<string, mixed>|null  $result
     * @return array<int, string>
     */
    private function unmetCriteria(?array $result): array
    {
        if ($result === null || ($result['status'] ?? null) === 'passed') {
            return [];
        }

        return $this->extractList($result['failed_checks'] ?? $result['missing'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<int, string>
     */
    private function toolNames(array $toolCalls): array
    {
        return array_values(array_unique(array_map(
            static fn (array $call): string => (string) ($call['name'] ?? 'unknown'),
            $toolCalls,
        )));
    }

    /**
     * @return array<int, string>
     */
    private function extractList(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
        }

        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                $value,
            )));
        }

        return [];
    }

    private function truncate(?string $value, int $limit = 1000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }
}
