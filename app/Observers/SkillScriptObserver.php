<?php

namespace App\Observers;

use App\Models\SkillScript;
use App\Services\Skills\ProjectSkillSync;

class SkillScriptObserver
{
    public function __construct(
        private readonly ProjectSkillSync $projectSkillSync,
    ) {}

    public function saved(SkillScript $skillScript): void
    {
        $this->projectSkillSync->exportSkill($skillScript->skill()->with('scripts')->firstOrFail());
    }

    public function deleted(SkillScript $skillScript): void
    {
        $skill = $skillScript->skill()->with('scripts')->first();

        if ($skill !== null) {
            $this->projectSkillSync->exportSkill($skill);
        }
    }
}
