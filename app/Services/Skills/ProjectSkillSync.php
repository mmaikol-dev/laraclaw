<?php

namespace App\Services\Skills;

use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ProjectSkillSync
{
    public function __construct(
        private readonly ?string $baseDirectory = null,
    ) {}

    public function exportSkill(Skill $skill): void
    {
        $skill->loadMissing('scripts');

        $skillDirectory = $this->skillDirectory($skill);
        $scriptsDirectory = $skillDirectory.'/scripts';

        File::ensureDirectoryExists($scriptsDirectory);
        File::put($skillDirectory.'/SKILL.md', $this->renderSkillMarkdown($skill));

        $expectedFiles = [];

        foreach ($skill->scripts as $script) {
            $scriptPath = $scriptsDirectory.'/'.$script->filename;
            $scriptDirectory = dirname($scriptPath);

            File::ensureDirectoryExists($scriptDirectory);
            File::put($scriptPath, $script->content);
            $expectedFiles[] = $scriptPath;
        }

        $this->removeStaleScriptFiles($scriptsDirectory, collect($expectedFiles));
    }

    /**
     * @param  iterable<int, Skill>  $skills
     */
    public function exportAll(iterable $skills): void
    {
        foreach ($skills as $skill) {
            $this->exportSkill($skill);
        }
    }

    public function deleteSkill(Skill $skill): void
    {
        File::deleteDirectory($this->skillDirectory($skill));
    }

    public function skillsRoot(): string
    {
        return $this->baseDirectory ?? base_path('.agents/db-skills');
    }

    private function skillDirectory(Skill $skill): string
    {
        return $this->skillsRoot().'/'.$this->skillSlug($skill->name);
    }

    private function skillSlug(string $name): string
    {
        return Str::slug($name, '-');
    }

    private function renderSkillMarkdown(Skill $skill): string
    {
        $dependencies = is_array($skill->dependencies) ? $skill->dependencies : [];
        $dependencyLines = $dependencies === []
            ? "dependencies: []\n"
            : "dependencies:\n".collect($dependencies)->map(fn (string $dependency): string => "  - {$dependency}")->implode("\n")."\n";

        return "---\n"
            ."name: {$skill->name}\n"
            ."description: {$skill->description}\n"
            ."category: {$skill->category}\n"
            ."created_by: {$skill->created_by}\n"
            ."version: {$skill->version}\n"
            .'is_active: '.($skill->is_active ? 'true' : 'false')."\n"
            ."source: database\n"
            .$dependencyLines
            ."---\n\n"
            .$skill->instructions."\n";
    }

    /**
     * @param  Collection<int, string>  $expectedFiles
     */
    private function removeStaleScriptFiles(string $scriptsDirectory, Collection $expectedFiles): void
    {
        if (! File::isDirectory($scriptsDirectory)) {
            return;
        }

        $expected = $expectedFiles->map(fn (string $path): string => str_replace('\\', '/', $path))->all();

        foreach (File::allFiles($scriptsDirectory) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (! in_array($path, $expected, true)) {
                File::delete($path);
            }
        }
    }
}
