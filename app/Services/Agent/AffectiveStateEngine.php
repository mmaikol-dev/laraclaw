<?php

namespace App\Services\Agent;

use App\Models\AgentSetting;

class AffectiveStateEngine
{
    public function __construct(
        private readonly AffectiveStateStore $store,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function begin(int $conversationId, string $userMessage): array
    {
        return $this->store->put($conversationId, [
            'fear_level' => 0.05,
            'joy_score' => 0.0,
            'sadness_count' => 0,
            'anger_level' => 0,
            'curiosity_score' => $this->scoreNovelty($userMessage),
            'love_weights' => [
                'user_goal' => 10,
                'safety_constraint' => 9,
            ],
            'guilt_flags' => [],
            'boredom_counter' => 0,
            'consecutive_failures' => 0,
            'repetition_counter' => 0,
            'last_tool_name' => null,
            'last_block_reason' => null,
            'user_goal' => (string) str($userMessage)->squish()->limit(220),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $conversationId): array
    {
        return $this->store->get($conversationId);
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_affective_state', true);
    }

    public function buildContext(int $conversationId): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        $state = $this->get($conversationId);
        $guidance = [];

        if ((float) $state['fear_level'] >= $this->fearThreshold()) {
            $guidance[] = 'Caution is elevated. Do not take destructive or irreversible actions without explicit user confirmation. Prefer inspection, dry runs, and safer alternatives first.';
        }

        if ((int) $state['sadness_count'] >= $this->sadnessThreshold()) {
            $guidance[] = 'Repeated failures were detected. Stop repeating the same approach. Summarize what failed and choose a materially different strategy.';
        }

        if ((int) $state['anger_level'] > 0) {
            $guidance[] = 'Persistence is elevated. You may retry transient failures, but keep retries bounded and report blockers clearly when they persist.';
        }

        if ((float) $state['curiosity_score'] >= $this->curiosityThreshold()) {
            $guidance[] = 'Novelty is elevated. Gather context before acting. Prefer reading files, checking state, or searching for evidence instead of assuming.';
        }

        if ((int) $state['boredom_counter'] >= $this->boredomThreshold()) {
            $guidance[] = 'Repetition was detected. Avoid using the same failed tool or pattern again without a meaningful change in strategy.';
        }

        if (($state['guilt_flags'] ?? []) !== []) {
            $guidance[] = 'Self-audit flags are active: '.implode(', ', $state['guilt_flags']).'. Correct these issues before finishing the task.';
        }

        if ($guidance === []) {
            return '';
        }

        $goal = $state['user_goal'] ? "Primary user goal: {$state['user_goal']}" : null;
        $love = "Persistent priorities: preserve the user's goal, keep actions safe, and avoid avoidable damage.";

        return implode("\n", array_filter([
            '=== Affective State Guidance ===',
            $goal,
            $love,
            ...array_map(fn (string $line): string => "- {$line}", $guidance),
        ]));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return null|array{fear_level: float, reason: string}
     */
    public function assessToolRisk(int $conversationId, string $toolName, array $arguments): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $risk = $this->detectRisk($toolName, $arguments);

        if ($risk === null) {
            return null;
        }

        $state = $this->store->mutate($conversationId, function (array $state) use ($risk, $toolName): array {
            $state['fear_level'] = min(1.0, max((float) $state['fear_level'], $risk['fear_level']));
            $state['last_tool_name'] = $toolName;
            $state['last_block_reason'] = $risk['reason'];

            return $state;
        });

        return [
            'fear_level' => (float) $state['fear_level'],
            'reason' => $risk['reason'],
        ];
    }

    public function shouldPauseForSafety(int $conversationId, string $toolName, array $arguments): bool
    {
        $assessment = $this->assessToolRisk($conversationId, $toolName, $arguments);

        return $assessment !== null && $assessment['fear_level'] >= $this->fearThreshold();
    }

    public function blockMessage(int $conversationId): string
    {
        $reason = (string) ($this->get($conversationId)['last_block_reason'] ?? 'This action appears high risk.');

        return "Affective safety check paused the action. {$reason} Ask the user to confirm the destructive step or choose a safer read-only approach.";
    }

    public function recordToolResult(int $conversationId, string $toolName, bool $succeeded, string $output): array
    {
        return $this->store->mutate($conversationId, function (array $state) use ($toolName, $succeeded, $output): array {
            $sameTool = (($state['last_tool_name'] ?? null) === $toolName);
            $state['last_tool_name'] = $toolName;

            if ($sameTool) {
                $state['repetition_counter'] = (int) $state['repetition_counter'] + 1;
            } else {
                $state['repetition_counter'] = 0;
            }

            if ($succeeded) {
                $state['joy_score'] = min(1.0, (float) $state['joy_score'] + 0.15);
                $state['sadness_count'] = max(0, (int) $state['sadness_count'] - 1);
                $state['anger_level'] = max(0, (int) $state['anger_level'] - 1);
                $state['consecutive_failures'] = 0;
                $state['boredom_counter'] = 0;
                $state['fear_level'] = max(0.0, (float) $state['fear_level'] - 0.1);

                if (in_array($toolName, ['web', 'browser', 'document', 'file'], true)) {
                    $state['curiosity_score'] = max(0.0, (float) $state['curiosity_score'] - 0.2);
                }
            } else {
                $state['sadness_count'] = (int) $state['sadness_count'] + 1;
                $state['consecutive_failures'] = (int) $state['consecutive_failures'] + 1;
                $state['anger_level'] = min($this->angerCap(), (int) $state['anger_level'] + 1);

                if ($sameTool) {
                    $state['boredom_counter'] = (int) $state['boredom_counter'] + 1;
                }

                $state['fear_level'] = min(1.0, (float) $state['fear_level'] + 0.08);

                if ($state['consecutive_failures'] >= $this->sadnessThreshold()) {
                    $state['guilt_flags'] = $this->pushFlag($state['guilt_flags'] ?? [], 'repeated_failures');
                }
            }

            if (trim($output) === '') {
                $state['guilt_flags'] = $this->pushFlag($state['guilt_flags'] ?? [], 'empty_tool_output');
            }

            return $state;
        });
    }

    public function recordAssistantTurn(int $conversationId, string $content, bool $hadToolCalls): array
    {
        return $this->store->mutate($conversationId, function (array $state) use ($content, $hadToolCalls): array {
            if (trim($content) === '' && ! $hadToolCalls) {
                $state['boredom_counter'] = (int) $state['boredom_counter'] + 1;
                $state['guilt_flags'] = $this->pushFlag($state['guilt_flags'] ?? [], 'empty_response');
            } else {
                $state['guilt_flags'] = array_values(array_filter(
                    $state['guilt_flags'] ?? [],
                    fn (string $flag): bool => $flag !== 'empty_response'
                ));
            }

            if ((int) $state['repetition_counter'] >= $this->boredomThreshold()) {
                $state['boredom_counter'] = max((int) $state['boredom_counter'], (int) $state['repetition_counter']);
            }

            return $state;
        });
    }

    public function recordException(int $conversationId): array
    {
        return $this->store->mutate($conversationId, function (array $state): array {
            $state['sadness_count'] = (int) $state['sadness_count'] + 1;
            $state['consecutive_failures'] = (int) $state['consecutive_failures'] + 1;
            $state['fear_level'] = min(1.0, (float) $state['fear_level'] + 0.1);
            $state['guilt_flags'] = $this->pushFlag($state['guilt_flags'] ?? [], 'run_failed');

            return $state;
        });
    }

    private function fearThreshold(): float
    {
        return (float) AgentSetting::get('fear_threshold', '0.6');
    }

    private function curiosityThreshold(): float
    {
        return (float) AgentSetting::get('curiosity_threshold', '0.45');
    }

    private function sadnessThreshold(): int
    {
        return (int) AgentSetting::get('sadness_threshold', 2);
    }

    private function boredomThreshold(): int
    {
        return (int) AgentSetting::get('boredom_threshold', 2);
    }

    private function angerCap(): int
    {
        return (int) AgentSetting::get('anger_cap', 2);
    }

    private function scoreNovelty(string $userMessage): float
    {
        $score = 0.15;
        $normalized = strtolower($userMessage);

        foreach (['analyze', 'investigate', 'debug', 'why', 'unknown', 'explore', 'research'] as $signal) {
            if (str_contains($normalized, $signal)) {
                $score += 0.1;
            }
        }

        if (str_word_count($userMessage) > 25) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return null|array{fear_level: float, reason: string}
     */
    private function detectRisk(string $toolName, array $arguments): ?array
    {
        if ($toolName === 'shell') {
            $command = strtolower((string) ($arguments['command'] ?? $arguments['cmd'] ?? ''));

            foreach ([
                'rm -rf' => 'The shell command deletes files recursively.',
                'git reset --hard' => 'The shell command discards local changes irreversibly.',
                'git clean -fd' => 'The shell command removes untracked files.',
                'mkfs' => 'The shell command reformats storage.',
                'shutdown' => 'The shell command powers off the machine.',
                'reboot' => 'The shell command restarts the machine.',
                'drop database' => 'The shell command appears to destroy database data.',
            ] as $pattern => $reason) {
                if (str_contains($command, $pattern)) {
                    return ['fear_level' => 0.95, 'reason' => $reason];
                }
            }
        }

        if ($toolName === 'file') {
            $action = strtolower((string) ($arguments['action'] ?? ''));

            if (in_array($action, ['delete', 'remove'], true)) {
                return [
                    'fear_level' => 0.85,
                    'reason' => 'The file tool is about to delete project files.',
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $flags
     * @return array<int, string>
     */
    private function pushFlag(array $flags, string $flag): array
    {
        if (! in_array($flag, $flags, true)) {
            $flags[] = $flag;
        }

        return array_values($flags);
    }
}
