<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Models\SkillScript;
use App\Services\Tools\SkillTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SkillScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_skill_has_many_scripts(): void
    {
        $skill = Skill::factory()->create();

        SkillScript::create([
            'skill_id' => $skill->id,
            'filename' => 'test.py',
            'description' => 'A test script',
            'content' => 'print("hello")',
        ]);

        $this->assertCount(1, $skill->fresh()->scripts);
    }

    public function test_skill_script_belongs_to_skill(): void
    {
        $skill = Skill::factory()->create();

        $script = SkillScript::create([
            'skill_id' => $skill->id,
            'filename' => 'helper.py',
            'description' => 'Helper script',
            'content' => 'pass',
        ]);

        $this->assertTrue($script->skill->is($skill));
    }

    public function test_read_action_writes_scripts_to_disk(): void
    {
        $skill = Skill::factory()->create(['name' => 'test-skill-scripts']);

        SkillScript::create([
            'skill_id' => $skill->id,
            'filename' => 'run.py',
            'description' => 'Runner',
            'content' => 'print("running")',
        ]);

        $tool = new SkillTool;
        $output = $tool->execute(['action' => 'read', 'name' => 'test-skill-scripts']);

        $this->assertStringContainsString('run.py', $output);
        $this->assertStringContainsString('Scripts (written to disk)', $output);

        $skillDir = storage_path('app/skills/test-skill-scripts');
        $this->assertFileExists($skillDir.'/run.py');
        $this->assertEquals('print("running")', File::get($skillDir.'/run.py'));

        File::deleteDirectory($skillDir);
    }

    public function test_read_action_without_scripts_shows_no_scripts_section(): void
    {
        Skill::factory()->create(['name' => 'no-scripts-skill']);

        $tool = new SkillTool;
        $output = $tool->execute(['action' => 'read', 'name' => 'no-scripts-skill']);

        $this->assertStringNotContainsString('Scripts (written to disk)', $output);
    }

    public function test_scripts_cascade_delete_with_skill(): void
    {
        $skill = Skill::factory()->create();

        SkillScript::create([
            'skill_id' => $skill->id,
            'filename' => 'cascade.py',
            'description' => '',
            'content' => '',
        ]);

        $scriptId = $skill->scripts()->first()->id;
        $skill->delete();

        $this->assertNull(SkillScript::find($scriptId));
    }
}
