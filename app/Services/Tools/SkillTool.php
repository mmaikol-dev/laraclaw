<?php

namespace App\Services\Tools;

use App\Models\Skill;
use App\Models\SkillScript;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SkillTool extends BaseTool
{
    public function getName(): string
    {
        return 'skill';
    }

    public function getDescription(): string
    {
        return 'Manage your own skills — read, create, update, or delete reusable skill instructions that improve your capabilities over time.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'read', 'create', 'update', 'delete'],
                    'description' => 'list: see all skills. read: get full instructions by name. create: define a new skill. update: revise a skill. delete: remove a skill.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Skill name (required for read, create, update, delete).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'One-sentence description of what this skill does (create/update).',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => Skill::CATEGORIES,
                    'description' => 'Category for classification (create/update).',
                ],
                'instructions' => [
                    'type' => 'string',
                    'description' => 'Full skill instructions — step-by-step guidance you will follow when applying this skill (create/update).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Whether this skill is active and injected into context (update).',
                ],
                'category_filter' => [
                    'type' => 'string',
                    'description' => 'Filter list results by category (list).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'list' => $this->list($arguments['category_filter'] ?? null),
            'read' => $this->read((string) ($arguments['name'] ?? '')),
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'delete' => $this->delete((string) ($arguments['name'] ?? '')),
            default => throw new RuntimeException('Unsupported skill action. Use: list, read, create, update, delete.'),
        };
    }

    private function list(?string $categoryFilter): string
    {
        $query = Skill::query()->orderByDesc('usage_count')->orderBy('name');

        if ($categoryFilter !== null && $categoryFilter !== '') {
            $query->where('category', $categoryFilter);
        }

        $skills = $query->get();

        if ($skills->isEmpty()) {
            return 'No skills defined yet. Use action: create to add your first skill.';
        }

        $lines = $skills->map(function (Skill $skill): string {
            $status = $skill->is_active ? '✓' : '✗';

            return sprintf(
                '[%s] %s (%s) — %s [used %d times]',
                $status,
                $skill->name,
                $skill->category,
                $skill->description,
                $skill->usage_count,
            );
        })->implode("\n");

        return "Skills ({$skills->count()}):\n\n{$lines}";
    }

    private function read(string $name): string
    {
        if ($name === '') {
            throw new RuntimeException('Skill name is required.');
        }

        $skill = Skill::with('scripts')->where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $skill->incrementUsage();

        $output = sprintf(
            "=== %s ===\nCategory: %s\nDescription: %s\nCreated by: %s\nUsed: %d times\n\n--- Instructions ---\n%s",
            $skill->name,
            $skill->category,
            $skill->description,
            $skill->created_by,
            $skill->usage_count,
            $skill->instructions,
        );

        /** @var Collection<int, SkillScript> $scripts */
        $scripts = $skill->scripts;

        if ($scripts->isNotEmpty()) {
            $skillDir = storage_path('app/skills/'.preg_replace('/[^a-zA-Z0-9_\-]/', '_', $skill->name));
            File::ensureDirectoryExists($skillDir);

            $scriptLines = [];

            foreach ($scripts as $script) {
                $filePath = $skillDir.'/'.$script->filename;
                File::put($filePath, $script->content);
                $scriptLines[] = sprintf('  - %s: %s (written to %s)', $script->filename, $script->description, $filePath);
            }

            $output .= "\n\n--- Scripts (written to disk) ---\n".implode("\n", $scriptLines);
            $output .= "\n\nScripts directory: {$skillDir}";
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function create(array $arguments): string
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $description = trim((string) ($arguments['description'] ?? ''));
        $category = (string) ($arguments['category'] ?? 'general');
        $instructions = trim((string) ($arguments['instructions'] ?? ''));

        if ($name === '' || $description === '' || $instructions === '') {
            throw new RuntimeException('name, description, and instructions are all required to create a skill.');
        }

        if (Skill::where('name', $name)->exists()) {
            throw new RuntimeException("A skill named '{$name}' already exists. Use action: update to modify it.");
        }

        if (! in_array($category, Skill::CATEGORIES, true)) {
            $category = 'general';
        }

        $skill = Skill::create([
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'instructions' => $instructions,
            'is_active' => true,
            'created_by' => 'agent',
        ]);

        return "Skill '{$skill->name}' created successfully in category '{$skill->category}'.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function update(array $arguments): string
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Skill name is required for update.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found. Use action: create instead.");
        }

        $fields = array_filter([
            'description' => isset($arguments['description']) ? trim((string) $arguments['description']) : null,
            'category' => isset($arguments['category']) ? (string) $arguments['category'] : null,
            'instructions' => isset($arguments['instructions']) ? trim((string) $arguments['instructions']) : null,
            'is_active' => isset($arguments['is_active']) ? (bool) $arguments['is_active'] : null,
        ], fn ($v) => $v !== null);

        if ($fields === []) {
            throw new RuntimeException('No fields to update. Provide description, category, instructions, or is_active.');
        }

        $skill->update($fields);

        return "Skill '{$skill->name}' updated successfully.";
    }

    private function delete(string $name): string
    {
        if ($name === '') {
            throw new RuntimeException('Skill name is required for delete.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $skill->delete();

        return "Skill '{$name}' deleted.";
    }
}
