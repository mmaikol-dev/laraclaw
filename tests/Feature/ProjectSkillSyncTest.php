<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\SkillScript;
use App\Services\Skills\ProjectSkillSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProjectSkillSyncTest extends TestCase
{
    private string $skillsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skillsRoot = '/tmp/laraclaw-project-skill-sync-tests';
        File::deleteDirectory($this->skillsRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->skillsRoot);

        parent::tearDown();
    }

    public function test_it_exports_skill_markdown_and_scripts_to_the_project(): void
    {
        $skill = new Skill([
            'name' => 'Telegram Audio Sender',
            'description' => 'Send generated audio to Telegram chats.',
            'category' => 'communication',
            'instructions' => "1. Read any saved credentials.\n2. Create the audio.\n3. Deliver it.",
            'created_by' => 'agent',
            'version' => 3,
            'is_active' => true,
            'dependencies' => ['audio-generator'],
        ]);
        $skill->setRelation('scripts', new Collection([
            new SkillScript([
                'filename' => 'send.py',
                'description' => 'Send a file to Telegram.',
                'content' => 'print("send")',
            ]),
        ]));

        $sync = new ProjectSkillSync($this->skillsRoot);
        $sync->exportSkill($skill);

        $skillDirectory = $this->skillsRoot.'/telegram-audio-sender';

        $this->assertFileExists($skillDirectory.'/SKILL.md');
        $this->assertFileExists($skillDirectory.'/scripts/send.py');
        $this->assertStringContainsString('name: Telegram Audio Sender', File::get($skillDirectory.'/SKILL.md'));
        $this->assertStringContainsString('source: database', File::get($skillDirectory.'/SKILL.md'));
        $this->assertStringContainsString('audio-generator', File::get($skillDirectory.'/SKILL.md'));
        $this->assertSame('print("send")', File::get($skillDirectory.'/scripts/send.py'));
    }

    public function test_it_deletes_the_project_copy_for_a_skill(): void
    {
        $skill = new Skill([
            'name' => 'Cleanup Skill',
            'description' => 'Temporary skill.',
            'category' => 'general',
            'instructions' => 'Remove the project copy when deleted.',
            'created_by' => 'user',
            'version' => 1,
            'is_active' => true,
            'dependencies' => [],
        ]);
        $skill->setRelation('scripts', new Collection);

        $sync = new ProjectSkillSync($this->skillsRoot);
        $sync->exportSkill($skill);
        $sync->deleteSkill($skill);

        $this->assertDirectoryDoesNotExist($this->skillsRoot.'/cleanup-skill');
    }
}
