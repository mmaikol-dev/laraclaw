<?php

namespace Tests\Feature;

use App\Models\Skill;
use App\Services\Tools\SkillTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SkillToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_skill_with_all_fields(): void
    {
        $tool = new SkillTool;

        $output = $tool->execute([
            'action' => 'create',
            'name' => 'deploy-checklist',
            'description' => 'Runs the pre-deploy checklist.',
            'category' => 'coding',
            'instructions' => "Check migrations.\nRun tests.\nDeploy.",
        ]);

        $this->assertStringContainsString("Skill 'deploy-checklist' created (v1) in category 'coding'.", $output);

        $skill = Skill::where('name', 'deploy-checklist')->firstOrFail();
        $this->assertSame('Runs the pre-deploy checklist.', $skill->description);
        $this->assertSame('coding', $skill->category);
        $this->assertTrue($skill->is_active);
        $this->assertSame(1, $skill->version);
        $this->assertSame(1, $skill->versions()->count());
    }

    public function test_create_derives_description_from_instructions_when_omitted(): void
    {
        $tool = new SkillTool;

        $output = $tool->execute([
            'action' => 'create',
            'category' => 'coding',
            'instructions' => "# Laravel Project Expert Skill\n\nYou are an expert Laravel Architect.\nUse Eloquent and Form Requests.",
            'name' => 'laravel-project-expert',
        ]);

        $this->assertStringContainsString("Skill 'laravel-project-expert' created", $output);

        $skill = Skill::where('name', 'laravel-project-expert')->firstOrFail();
        $this->assertSame('You are an expert Laravel Architect.', $skill->description);
    }

    public function test_create_derives_description_strips_markdown_and_truncates_long_lines(): void
    {
        $tool = new SkillTool;

        $longLine = str_repeat('word ', 40);

        $tool->execute([
            'action' => 'create',
            'instructions' => "- {$longLine}",
            'name' => 'truncated-skill',
        ]);

        $skill = Skill::where('name', 'truncated-skill')->firstOrFail();

        $this->assertLessThanOrEqual(121, mb_strlen($skill->description));
        $this->assertStringEndsWith('…', $skill->description);
    }

    public function test_create_reports_missing_fields_by_name(): void
    {
        $tool = new SkillTool;

        try {
            $tool->execute(['action' => 'create', 'instructions' => 'Do the thing.']);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing fields: name', $e->getMessage());
        }

        try {
            $tool->execute(['action' => 'create', 'name' => 'no-instructions']);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing fields: instructions', $e->getMessage());
        }
    }

    public function test_create_rejects_duplicate_name(): void
    {
        Skill::factory()->create(['name' => 'existing-skill']);

        $tool = new SkillTool;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A skill named 'existing-skill' already exists.");

        $tool->execute([
            'action' => 'create',
            'name' => 'existing-skill',
            'description' => 'Duplicate.',
            'instructions' => 'Instructions.',
        ]);
    }
}
