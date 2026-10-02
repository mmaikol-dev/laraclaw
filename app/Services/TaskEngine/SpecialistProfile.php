<?php

namespace App\Services\TaskEngine;

/**
 * Immutable description of a specialist agent profile used to dispatch task
 * steps. A specialist pairs a system prompt (how to behave) with a model tier
 * (which model to run) and a set of preferred tools.
 */
final class SpecialistProfile
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $systemPrompt,
        public readonly string $modelTier,
        public readonly array $preferredTools = [],
    ) {}

    /**
     * @return array<string, string|array<int, string>>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'system_prompt' => $this->systemPrompt,
            'model_tier' => $this->modelTier,
            'preferred_tools' => $this->preferredTools,
        ];
    }
}
