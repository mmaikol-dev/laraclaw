<?php

namespace App\Console\Commands;

use App\Models\Skill;
use App\Services\Skills\ProjectSkillSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-project-skills')]
#[Description('Export database-backed skills into project files under .agents/db-skills')]
class SyncProjectSkills extends Command
{
    public function __construct(
        private readonly ProjectSkillSync $projectSkillSync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $skills = Skill::with('scripts')->orderBy('name')->get();

        $this->projectSkillSync->exportAll($skills);

        $this->components->info("Exported {$skills->count()} skills to {$this->projectSkillSync->skillsRoot()}.");

        return self::SUCCESS;
    }
}
