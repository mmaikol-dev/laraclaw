<?php

namespace App\Observers;

use App\Models\Skill;
use App\Services\Skills\ProjectSkillSync;

class SkillObserver
{
    public function __construct(
        private readonly ProjectSkillSync $projectSkillSync,
    ) {}

    public function saved(Skill $skill): void
    {
        $this->projectSkillSync->exportSkill($skill);
    }

    public function deleted(Skill $skill): void
    {
        $this->projectSkillSync->deleteSkill($skill);
    }
}
