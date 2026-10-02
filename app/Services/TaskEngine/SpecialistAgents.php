<?php

namespace App\Services\TaskEngine;

use App\Models\Task;
use App\Models\TaskStep;

/**
 * Resolves the right specialist agent for a task step.
 *
 * The task engine is an executor; specialists give it role-specific guidance
 * (system prompt), model routing (tier), and tool preferences so that planning,
 * coding, debugging, testing, reviewing and security work each get a tailored
 * context instead of a generic one-size-fits-all prompt.
 */
class SpecialistAgents
{
    /**
     * @return array<string, SpecialistProfile>
     */
    public function all(): array
    {
        $definitions = [
            'planner' => new SpecialistProfile(
                slug: 'planner',
                name: 'Planner',
                systemPrompt: 'You are the planning specialist. Break goals into small, atomic, verifiable steps. Never execute work during planning — produce the plan only.',
                modelTier: 'reasoning',
                preferredTools: ['memory', 'project'],
            ),
            'researcher' => new SpecialistProfile(
                slug: 'researcher',
                name: 'Researcher',
                systemPrompt: 'You are the research specialist. Gather facts from files, the web and project state before proposing any change. Cite what you inspected and highlight uncertainty.',
                modelTier: 'reasoning',
                preferredTools: ['file', 'web', 'browser', 'memory'],
            ),
            'coder' => new SpecialistProfile(
                slug: 'coder',
                name: 'Coder',
                systemPrompt: 'You are the implementation specialist. Write minimal, idiomatic, tested code matching the project conventions. Prefer small, reviewable diffs and do not leave debug artifacts.',
                modelTier: 'coding',
                preferredTools: ['file', 'shell', 'project', 'skill', 'memory'],
            ),
            'debugger' => new SpecialistProfile(
                slug: 'debugger',
                name: 'Debugger',
                systemPrompt: 'You are the debugging specialist. Reproduce the failure first, inspect logs and recent changes, identify the root cause, then fix it. Never guess — verify the fix after applying it.',
                modelTier: 'coding',
                preferredTools: ['file', 'shell', 'project', 'memory'],
            ),
            'tester' => new SpecialistProfile(
                slug: 'tester',
                name: 'Tester',
                systemPrompt: 'You are the testing specialist. Add or update tests for every change, cover happy paths and failure paths, and run the affected tests to prove they pass.',
                modelTier: 'coding',
                preferredTools: ['shell', 'project', 'file'],
            ),
            'reviewer' => new SpecialistProfile(
                slug: 'reviewer',
                name: 'Reviewer',
                systemPrompt: 'You are the code review specialist. Independently verify claims: run checks, inspect diffs and confirm acceptance criteria before declaring done. Look for regressions, edge cases and security issues.',
                modelTier: 'review',
                preferredTools: ['shell', 'project', 'file'],
            ),
            'security' => new SpecialistProfile(
                slug: 'security',
                name: 'Security Reviewer',
                systemPrompt: 'You are the security specialist. Audit for injection, secrets, unsafe paths and privilege issues. Escalate anything suspicious and never bypass existing safety controls.',
                modelTier: 'complex',
                preferredTools: ['file', 'project', 'memory'],
            ),
        ];

        return $definitions;
    }

    public function bySlug(string $slug): ?SpecialistProfile
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * Resolve the best specialist for a step from its description and the task goal.
     */
    public function resolve(Task $task, ?TaskStep $step = null): SpecialistProfile
    {
        $text = strtolower(($step?->description ?? '').' '.($step?->prompt ?? '').' '.$task->goal);

        return match (true) {
            $this->matches($text, ['plan', 'strateg', 'roadmap', 'architecture', 'break down', 'outline']) => $this->bySlug('planner'),
            $this->matches($text, ['research', 'investigat', 'analy', 'survey', 'summariz', 'find out', 'gather', 'learn']) => $this->bySlug('researcher'),
            $this->matches($text, ['test', 'testcase', 'assert', 'phpunit', 'coverage']) => $this->bySlug('tester'),
            $this->matches($text, ['debug', 'fix bug', 'crash', 'traceback', 'exception', 'stack trace']) => $this->bySlug('debugger'),
            $this->matches($text, ['secur', 'vulnerab', 'injection', 'xss', 'csrf', 'auth', 'permission', 'sanitize', 'secret']) => $this->bySlug('security'),
            $this->matches($text, ['review', 'audit', 'refactor', 'cleanup', 'inspect code', 'verify']) => $this->bySlug('reviewer'),
            $this->matches($text, ['implement', 'write', 'add feature', 'migrat', 'build', 'create', 'update', 'refactor']) => $this->bySlug('coder'),
            default => $this->bySlug('coder'),
        };
    }

    /**
     * Build the system-prompt block for a specialist to embed in a step prompt.
     */
    public function buildPromptContext(SpecialistProfile $specialist, Task $task): string
    {
        $tools = implode(', ', $specialist->preferredTools);
        $complexity = $task->complexity_level ?? 'auto';

        return "\n\nSpecialist role: {$specialist->name}\n"
            ."Role guidance: {$specialist->systemPrompt}\n"
            ."Preferred tools: {$tools}\n"
            ."Task complexity: {$complexity}";
    }

    /**
     * @return array<int, array<string, string|array<int, string>>>
     */
    public function summary(): array
    {
        return array_map(
            fn (SpecialistProfile $profile): array => $profile->toArray(),
            array_values($this->all()),
        );
    }

    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
