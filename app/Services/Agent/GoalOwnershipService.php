<?php

namespace App\Services\Agent;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\TaskLog;
use Illuminate\Support\Str;

class GoalOwnershipService
{
    /**
     * @param  array<int, string>  $completionCriteria
     */
    public function beginRun(Conversation $conversation, string $userMessage, ?string $identityLabel = null, array $completionCriteria = []): void
    {
        $goal = Str::of($userMessage)->squish()->limit(500)->toString();

        $conversation->forceFill([
            'identity_label' => $identityLabel ?: $conversation->identity_label,
            'active_goal' => $goal,
            'completion_criteria' => $completionCriteria !== [] ? $completionCriteria : ($conversation->completion_criteria ?: [
                'Carry out the requested task instead of stopping at a partial answer.',
                'Verify the outcome using tests, logs, state checks, or direct evidence when available.',
                'Report remaining blockers, risks, or unverified assumptions clearly.',
            ]),
            'verification_status' => 'in_progress',
            'next_action' => 'Inspect the environment, make progress, and verify the outcome before finishing.',
            'last_resumed_at' => now(),
        ])->save();
    }

    public function buildPromptContext(Conversation $conversation): string
    {
        $criteria = $conversation->completion_criteria ?? [];
        $criteriaText = $criteria === [] ? '' : '- '.implode("\n- ", $criteria);
        $resumableState = $conversation->resumable_state ?? [];
        $pendingHypotheses = data_get($resumableState, 'pending_hypotheses', []);
        $constraints = data_get($resumableState, 'discovered_constraints', []);

        $context = "\n\nOutcome ownership:\n";
        $context .= 'Current goal: '.($conversation->active_goal ?: 'None set')."\n";
        $context .= 'Verification status: '.($conversation->verification_status ?: 'unverified')."\n";

        if ($criteriaText !== '') {
            $context .= "Completion criteria:\n{$criteriaText}\n";
        }

        if ($pendingHypotheses !== []) {
            $context .= "Pending hypotheses:\n- ".implode("\n- ", $pendingHypotheses)."\n";
        }

        if ($constraints !== []) {
            $context .= "Known constraints:\n- ".implode("\n- ", $constraints)."\n";
        }

        if ($conversation->next_action) {
            $context .= "Carry-over next action: {$conversation->next_action}\n";
        }

        $context .= 'Own the end result: act, verify, check regressions when relevant, and only stop when the completion criteria are met or a concrete blocker remains.';

        return $context;
    }

    public function finalizeRun(Conversation $conversation, Message $assistantMessage): void
    {
        $recentTaskLogs = TaskLog::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->limit(8)
            ->get();

        $failedLogs = $recentTaskLogs->where('status', 'error')->values();
        $toolNames = $recentTaskLogs->pluck('tool_name')->unique()->values()->all();
        $content = Str::lower((string) $assistantMessage->content);
        $hasVerificationLanguage = Str::contains($content, [
            'verified',
            'confirmed',
            'checked',
            'tested',
            'resolved',
            'logs',
            'regression',
        ]);

        $verificationStatus = match (true) {
            $failedLogs->isEmpty() && ($hasVerificationLanguage || $recentTaskLogs->where('status', 'success')->isNotEmpty()) => 'verified',
            $recentTaskLogs->isNotEmpty() => 'partial',
            default => 'unverified',
        };

        $constraints = $this->extractConstraintPhrases((string) $assistantMessage->content);
        $pendingHypotheses = $failedLogs
            ->pluck('error_message')
            ->filter()
            ->map(fn (?string $error): string => Str::limit((string) $error, 160))
            ->take(3)
            ->values()
            ->all();

        $nextAction = $verificationStatus === 'verified'
            ? null
            : ($pendingHypotheses[0] ?? 'Continue from the last verified step and gather stronger evidence.');

        $verificationNotes = $this->summarizeVerification($toolNames, $failedLogs->pluck('tool_name')->unique()->values()->all(), $verificationStatus);

        $conversation->forceFill([
            'verification_status' => $verificationStatus,
            'verification_notes' => $verificationNotes,
            'next_action' => $nextAction,
            'resumable_state' => [
                'recent_tools' => $toolNames,
                'failed_tools' => $failedLogs->pluck('tool_name')->unique()->values()->all(),
                'pending_hypotheses' => $pendingHypotheses,
                'discovered_constraints' => $constraints,
                'last_response_excerpt' => Str::limit((string) $assistantMessage->content, 400),
            ],
            'last_verified_at' => $verificationStatus === 'verified' ? now() : $conversation->last_verified_at,
        ])->save();
    }

    /**
     * @param  array<int, string>  $recentTools
     * @param  array<int, string>  $failedTools
     */
    private function summarizeVerification(array $recentTools, array $failedTools, string $verificationStatus): string
    {
        $summary = 'Verification status: '.$verificationStatus.'.';

        if ($recentTools !== []) {
            $summary .= ' Recent tools: '.implode(', ', $recentTools).'.';
        }

        if ($failedTools !== []) {
            $summary .= ' Failed tools: '.implode(', ', $failedTools).'.';
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    private function extractConstraintPhrases(string $content): array
    {
        return collect(preg_split('/(?<=[.!?])\s+/', $content) ?: [])
            ->map(fn (string $sentence): string => trim($sentence))
            ->filter(fn (string $sentence): bool => $sentence !== '' && Str::contains(Str::lower($sentence), [
                'cannot',
                'unable',
                'blocked',
                'failed',
                'missing',
                'not available',
                'permission',
            ]))
            ->take(3)
            ->values()
            ->all();
    }
}
