<?php

namespace App\Services\TaskEngine;

use App\Models\Task;
use App\Services\Agent\OllamaService;

/**
 * Routes tasks and steps to appropriate models based on complexity.
 *
 * The model is an executor; this router decides which model the executor uses.
 * Providers are derived from the existing Ollama configuration and are
 * overridable via environment variables.
 */
class ModelRouter
{
    public function __construct(
        protected ?OllamaService $ollama = null,
    ) {}

    /**
     * The model tier to use for a specialist agent profile.
     */
    public function modelForSpecialist(SpecialistProfile $specialist): string
    {
        return $this->modelForComplexity($specialist->modelTier);
    }

    /**
     * The default agent model (existing Ollama config).
     */
    public function defaultModel(): string
    {
        return $this->ollama?->agentModel ?? (string) config('ollama.agent_model', 'glm-5:cloud');
    }

    /**
     * Returns the model for a given complexity level.
     */
    public function modelForComplexity(string $complexity): string
    {
        $map = [
            'simple' => config('agent.models.simple', $this->defaultModel()),
            'coding' => config('agent.models.coding', $this->defaultModel()),
            'reasoning' => config('agent.models.reasoning', $this->defaultModel()),
            'review' => config('agent.models.review', $this->defaultModel()),
            'complex' => config('agent.models.complex', $this->defaultModel()),
        ];

        return (string) ($map[$complexity] ?? $this->defaultModel());
    }

    /**
     * Classify a task goal's complexity.
     */
    public function classifyComplexity(string $goal): string
    {
        $normalized = strtolower($goal);
        $wordCount = str_word_count($goal);

        $complexity = 0;

        if ($wordCount >= 40) {
            $complexity++;
        }

        foreach (['implement', 'fix', 'refactor', 'architecture', 'security', 'debug', 'integrat', 'design', 'build', 'migrat'] as $signal) {
            if (str_contains($normalized, $signal)) {
                $complexity++;
                break;
            }
        }

        foreach (['inspect', 'read', 'summarize', 'list', 'check', 'what', 'why'] as $signal) {
            if (str_contains($normalized, $signal)) {
                break;
            }
        }

        return match (true) {
            $complexity >= 2 => 'complex',
            $complexity >= 1 => 'coding',
            default => 'simple',
        };
    }

    /**
     * Assign a model to a task based on its complexity.
     */
    public function modelForTask(Task $task): string
    {
        $complexity = $task->complexity_level ?? $this->classifyComplexity($task->goal);

        return $this->modelForComplexity($complexity);
    }

    /**
     * Escalate to a stronger model on repeated failure.
     *
     * @return array{model: string, escalated: bool, reason: string}
     */
    public function escalateForFailure(Task $task, string $reason): array
    {
        [$fromModel, $toModel] = $this->escalationPair($task->model ?: $this->modelForTask($task));

        return [
            'model' => $toModel,
            'escalated' => $fromModel !== $toModel,
            'reason' => $reason,
        ];
    }

    /**
     * Return a human-understandable picture of the routing.
     *
     * @return array<string, string>
     */
    public function summary(): array
    {
        return [
            'simple' => $this->modelForComplexity('simple'),
            'coding' => $this->modelForComplexity('coding'),
            'reasoning' => $this->modelForComplexity('reasoning'),
            'review' => $this->modelForComplexity('review'),
            'complex' => $this->modelForComplexity('complex'),
        ];
    }

    /**
     * Determine the escalation target for a given model string.
     *
     * @return array{0: string, 1: string}
     */
    private function escalationPair(string $currentModel): array
    {
        $tiers = [
            config('agent.models.review', ''),
            config('agent.models.reasoning', ''),
            config('agent.models.complex', ''),
            config('agent.models.coding', ''),
            config('agent.models.simple', ''),
        ];
        $tiers = array_values(array_filter([
            ...$tiers,
            $this->defaultModel(),
        ], fn (string $m): bool => $m !== ''));

        $currentIdx = array_search($currentModel, $tiers, true);

        if ($currentIdx === false || $currentIdx === 0) {
            return [$currentModel, $currentModel];
        }

        return [$tiers[$currentIdx], $tiers[0]];
    }
}
