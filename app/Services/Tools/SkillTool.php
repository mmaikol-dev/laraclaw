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
        return 'Manage reusable skills — read, create, update, delete, fork, merge, export, import, validate, version history, dependencies, and templates.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'list', 'read', 'create', 'update', 'delete',
                        'fork', 'merge', 'export', 'import',
                        'validate', 'history', 'rollback',
                        'add_dependency', 'remove_dependency',
                        'list_templates', 'create_from_template',
                    ],
                    'description' => implode(' ', [
                        'list: see all skills.',
                        'read: get full instructions by name.',
                        'create: define a new skill.',
                        'update: revise a skill (auto-snapshots the previous version).',
                        'delete: remove a skill.',
                        'fork: copy an existing skill under a new name — pass name (source) and new_name (fork).',
                        'merge: combine two skills into one — pass name, merge_name (second skill), and instructions for the merged result.',
                        'export: export a skill as a portable JSON string — pass name.',
                        'import: import a previously exported skill JSON — pass import_data.',
                        'validate: check a skill\'s instructions for common issues — pass name.',
                        'history: list all saved versions of a skill — pass name.',
                        'rollback: restore a skill to a previous version — pass name and version number.',
                        'add_dependency: declare that a skill requires another skill — pass name and dependency.',
                        'remove_dependency: remove a declared dependency — pass name and dependency.',
                        'list_templates: show all built-in skill templates.',
                        'create_from_template: create a new skill pre-filled from a template — pass template and name.',
                    ]),
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Skill name (required for most actions).',
                ],
                'new_name' => [
                    'type' => 'string',
                    'description' => 'New skill name for fork action.',
                ],
                'merge_name' => [
                    'type' => 'string',
                    'description' => 'Name of the second skill to merge into the first.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'One-sentence description of what this skill does (create/update/fork/merge).',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => Skill::CATEGORIES,
                    'description' => 'Category for classification (create/update/fork).',
                ],
                'instructions' => [
                    'type' => 'string',
                    'description' => 'Full skill instructions (create/update/merge).',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Whether this skill is active and injected into context (update).',
                ],
                'category_filter' => [
                    'type' => 'string',
                    'description' => 'Filter list results by category.',
                ],
                'dependency' => [
                    'type' => 'string',
                    'description' => 'Name of a skill to add or remove as a dependency.',
                ],
                'version' => [
                    'type' => 'integer',
                    'description' => 'Version number for rollback action.',
                ],
                'change_note' => [
                    'type' => 'string',
                    'description' => 'Optional note describing what changed (update/rollback).',
                ],
                'import_data' => [
                    'type' => 'string',
                    'description' => 'JSON string from a previous export action.',
                ],
                'template' => [
                    'type' => 'string',
                    'description' => 'Template key for create_from_template (see list_templates for options).',
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
            'fork' => $this->fork($arguments),
            'merge' => $this->merge($arguments),
            'export' => $this->export((string) ($arguments['name'] ?? '')),
            'import' => $this->import((string) ($arguments['import_data'] ?? '')),
            'validate' => $this->validate((string) ($arguments['name'] ?? '')),
            'history' => $this->history((string) ($arguments['name'] ?? '')),
            'rollback' => $this->rollback((string) ($arguments['name'] ?? ''), (int) ($arguments['version'] ?? 0), (string) ($arguments['change_note'] ?? '')),
            'add_dependency' => $this->addDependency((string) ($arguments['name'] ?? ''), (string) ($arguments['dependency'] ?? '')),
            'remove_dependency' => $this->removeDependency((string) ($arguments['name'] ?? ''), (string) ($arguments['dependency'] ?? '')),
            'list_templates' => $this->listTemplates(),
            'create_from_template' => $this->createFromTemplate($arguments),
            default => throw new RuntimeException('Unsupported skill action.'),
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
            $deps = $skill->dependencies ? ' [needs: '.implode(', ', $skill->dependencies).']' : '';
            $tpl = $skill->template ? " [from:{$skill->template}]" : '';

            return sprintf(
                '[%s] %s v%d (%s)%s%s — %s [used %d times]',
                $status,
                $skill->name,
                $skill->version,
                $skill->category,
                $deps,
                $tpl,
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

        $deps = $skill->dependencies ? "\nDependencies: ".implode(', ', $skill->dependencies) : '';

        $output = sprintf(
            "=== %s (v%d) ===\nCategory: %s\nDescription: %s\nCreated by: %s\nUsed: %d times%s\n\n--- Instructions ---\n%s",
            $skill->name,
            $skill->version,
            $skill->category,
            $skill->description,
            $skill->created_by,
            $skill->usage_count,
            $deps,
            $skill->instructions,
        );

        // Auto-load dependency instructions
        if (! empty($skill->dependencies)) {
            $depSkills = Skill::whereIn('name', $skill->dependencies)->where('is_active', true)->get();

            if ($depSkills->isNotEmpty()) {
                $depLines = $depSkills->map(fn (Skill $s) => "  [{$s->name}]: {$s->instructions}")->implode("\n\n");
                $output .= "\n\n--- Dependency Instructions ---\n".$depLines;
            }
        }

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
            'version' => 1,
        ]);

        // Snapshot the initial version
        $skill->snapshotVersion('agent', 'Initial version.');

        return "Skill '{$skill->name}' created (v1) in category '{$skill->category}'.";
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

        // Snapshot current state before overwriting
        $changeNote = trim((string) ($arguments['change_note'] ?? '')) ?: null;
        $skill->snapshotVersion('agent', $changeNote);

        if (isset($fields['instructions'])) {
            $fields['version'] = $skill->version + 1;
        }

        $skill->update($fields);

        return "Skill '{$skill->name}' updated to v{$skill->version}.";
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function fork(array $arguments): string
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $newName = trim((string) ($arguments['new_name'] ?? ''));

        if ($name === '' || $newName === '') {
            throw new RuntimeException('fork requires name (source) and new_name (fork).');
        }

        $source = Skill::where('name', $name)->first();

        if ($source === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        if (Skill::where('name', $newName)->exists()) {
            throw new RuntimeException("A skill named '{$newName}' already exists.");
        }

        $fork = Skill::create([
            'name' => $newName,
            'description' => (string) ($arguments['description'] ?? $source->description.' (fork of '.$source->name.')'),
            'category' => (string) ($arguments['category'] ?? $source->category),
            'instructions' => $source->instructions,
            'is_active' => true,
            'created_by' => 'agent',
            'version' => 1,
            'dependencies' => $source->dependencies,
            'template' => $source->template,
        ]);

        $fork->snapshotVersion('agent', "Forked from '{$source->name}' v{$source->version}.");

        return "Skill '{$newName}' created as a fork of '{$source->name}' (v{$source->version}).";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function merge(array $arguments): string
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        $mergeName = trim((string) ($arguments['merge_name'] ?? ''));
        $instructions = trim((string) ($arguments['instructions'] ?? ''));
        $description = trim((string) ($arguments['description'] ?? ''));

        if ($name === '' || $mergeName === '' || $instructions === '') {
            throw new RuntimeException('merge requires name, merge_name, and instructions for the merged result.');
        }

        $skillA = Skill::where('name', $name)->first();
        $skillB = Skill::where('name', $mergeName)->first();

        if ($skillA === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        if ($skillB === null) {
            throw new RuntimeException("Skill '{$mergeName}' not found.");
        }

        $skillA->snapshotVersion('agent', "Before merge with '{$mergeName}'.");
        $skillA->update([
            'instructions' => $instructions,
            'description' => $description !== '' ? $description : $skillA->description,
            'version' => $skillA->version + 1,
        ]);

        return "Skill '{$name}' updated to v{$skillA->version} with merged content from '{$mergeName}'. Skill '{$mergeName}' was not deleted — remove it manually if no longer needed.";
    }

    private function export(string $name): string
    {
        if ($name === '') {
            throw new RuntimeException('Skill name is required for export.');
        }

        $skill = Skill::with('scripts')->where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $data = [
            'schema' => 'laraclaw-skill-v1',
            'name' => $skill->name,
            'description' => $skill->description,
            'category' => $skill->category,
            'instructions' => $skill->instructions,
            'version' => $skill->version,
            'dependencies' => $skill->dependencies ?? [],
            'template' => $skill->template,
            'scripts' => $skill->scripts->map(fn (SkillScript $s) => [
                'filename' => $s->filename,
                'description' => $s->description,
                'content' => $s->content,
            ])->all(),
            'exported_at' => now()->toIso8601String(),
        ];

        return "Export data for skill '{$name}':\n\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function import(string $importData): string
    {
        if ($importData === '') {
            throw new RuntimeException('import_data is required for import.');
        }

        $data = json_decode($importData, true);

        if (! is_array($data) || ($data['schema'] ?? '') !== 'laraclaw-skill-v1') {
            throw new RuntimeException('Invalid import data. Must be JSON exported from the export action.');
        }

        $name = (string) ($data['name'] ?? '');

        if ($name === '') {
            throw new RuntimeException('Import data is missing the skill name.');
        }

        $exists = Skill::where('name', $name)->first();

        if ($exists !== null) {
            // Update existing skill
            $exists->snapshotVersion('agent', 'Before import overwrite.');
            $exists->update([
                'description' => $data['description'] ?? $exists->description,
                'category' => $data['category'] ?? $exists->category,
                'instructions' => $data['instructions'] ?? $exists->instructions,
                'version' => ($exists->version) + 1,
                'dependencies' => $data['dependencies'] ?? $exists->dependencies,
                'template' => $data['template'] ?? $exists->template,
            ]);

            return "Skill '{$name}' updated from import (now v{$exists->version}).";
        }

        $skill = Skill::create([
            'name' => $name,
            'description' => $data['description'] ?? '',
            'category' => in_array($data['category'] ?? '', Skill::CATEGORIES, true) ? $data['category'] : 'general',
            'instructions' => $data['instructions'] ?? '',
            'is_active' => true,
            'created_by' => 'agent',
            'version' => (int) ($data['version'] ?? 1),
            'dependencies' => $data['dependencies'] ?? null,
            'template' => $data['template'] ?? null,
        ]);

        $skill->snapshotVersion('agent', 'Imported.');

        return "Skill '{$name}' imported successfully (v{$skill->version}).";
    }

    private function validate(string $name): string
    {
        if ($name === '') {
            throw new RuntimeException('Skill name is required for validate.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $issues = [];
        $warnings = [];

        // Length checks
        if (strlen($skill->instructions) < 50) {
            $issues[] = 'Instructions are very short (< 50 chars) — may not provide enough guidance.';
        }

        if (strlen($skill->description) < 10) {
            $issues[] = 'Description is too short — should be at least one full sentence.';
        }

        // Structure checks
        if (! str_contains($skill->instructions, "\n")) {
            $warnings[] = 'Instructions have no line breaks — consider using numbered steps or bullet points.';
        }

        if (str_word_count($skill->instructions) < 20) {
            $warnings[] = 'Instructions are brief — more detail helps the agent apply the skill accurately.';
        }

        // Dependency validation
        if (! empty($skill->dependencies)) {
            $missing = array_filter(
                $skill->dependencies,
                fn (string $dep) => ! Skill::where('name', $dep)->exists(),
            );

            if (! empty($missing)) {
                $issues[] = 'Missing dependencies: '.implode(', ', $missing).'. These skills do not exist.';
            }
        }

        // Circular dependency check (one level deep)
        if (! empty($skill->dependencies)) {
            $circularDeps = Skill::whereIn('name', $skill->dependencies)
                ->whereJsonContains('dependencies', $skill->name)
                ->pluck('name')
                ->all();

            if (! empty($circularDeps)) {
                $issues[] = 'Circular dependency detected with: '.implode(', ', $circularDeps).'.';
            }
        }

        if ($issues === [] && $warnings === []) {
            return "Skill '{$name}' passed validation with no issues.";
        }

        $output = "Validation report for '{$name}':";

        if ($issues !== []) {
            $output .= "\n\nISSUES (should fix):\n".implode("\n", array_map(fn ($i) => '  ✗ '.$i, $issues));
        }

        if ($warnings !== []) {
            $output .= "\n\nWARNINGS (consider fixing):\n".implode("\n", array_map(fn ($w) => '  ⚠ '.$w, $warnings));
        }

        return $output;
    }

    private function history(string $name): string
    {
        if ($name === '') {
            throw new RuntimeException('Skill name is required for history.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $versions = $skill->versions()->get();

        if ($versions->isEmpty()) {
            return "No version history for '{$name}'. Updates will be snapshotted automatically going forward.";
        }

        $lines = $versions->map(fn ($v) => sprintf(
            '  v%d — %s by %s%s',
            $v->version,
            $v->created_at->format('Y-m-d H:i'),
            $v->changed_by,
            $v->change_note ? ' ('.$v->change_note.')' : '',
        ))->implode("\n");

        return "Version history for '{$name}' (current: v{$skill->version}):\n\n{$lines}";
    }

    private function rollback(string $name, int $version, string $changeNote): string
    {
        if ($name === '') {
            throw new RuntimeException('Skill name is required for rollback.');
        }

        if ($version < 1) {
            throw new RuntimeException('A valid version number is required for rollback.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $snap = $skill->versions()->where('version', $version)->first();

        if ($snap === null) {
            throw new RuntimeException("Version {$version} of skill '{$name}' not found.");
        }

        // Snapshot current state before rolling back
        $skill->snapshotVersion('agent', "Before rollback to v{$version}.");

        $newVersion = $skill->version + 1;
        $skill->update([
            'instructions' => $snap->instructions,
            'description' => $snap->description,
            'version' => $newVersion,
        ]);

        return "Skill '{$name}' rolled back to v{$version} content (now saved as v{$newVersion}).".($changeNote ? " Note: {$changeNote}" : '');
    }

    private function addDependency(string $name, string $dependency): string
    {
        if ($name === '' || $dependency === '') {
            throw new RuntimeException('add_dependency requires name and dependency.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        if (! Skill::where('name', $dependency)->exists()) {
            throw new RuntimeException("Dependency skill '{$dependency}' does not exist.");
        }

        if ($name === $dependency) {
            throw new RuntimeException('A skill cannot depend on itself.');
        }

        $deps = $skill->dependencies ?? [];

        if (in_array($dependency, $deps, true)) {
            return "Skill '{$name}' already depends on '{$dependency}'.";
        }

        $deps[] = $dependency;
        $skill->update(['dependencies' => $deps]);

        return "Dependency '{$dependency}' added to skill '{$name}'.";
    }

    private function removeDependency(string $name, string $dependency): string
    {
        if ($name === '' || $dependency === '') {
            throw new RuntimeException('remove_dependency requires name and dependency.');
        }

        $skill = Skill::where('name', $name)->first();

        if ($skill === null) {
            throw new RuntimeException("Skill '{$name}' not found.");
        }

        $deps = array_values(array_filter($skill->dependencies ?? [], fn (string $d) => $d !== $dependency));
        $skill->update(['dependencies' => $deps ?: null]);

        return "Dependency '{$dependency}' removed from skill '{$name}'.";
    }

    private function listTemplates(): string
    {
        $lines = collect(Skill::TEMPLATES)->map(function (array $tpl, string $key): string {
            return "  {$key} ({$tpl['category']}): {$tpl['description']}";
        })->implode("\n");

        return "Available skill templates:\n\n{$lines}\n\nUse action: create_from_template with template: <key> and name: <your-skill-name>.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function createFromTemplate(array $arguments): string
    {
        $templateKey = trim((string) ($arguments['template'] ?? ''));
        $name = trim((string) ($arguments['name'] ?? ''));

        if ($templateKey === '' || $name === '') {
            throw new RuntimeException('create_from_template requires template and name.');
        }

        if (! isset(Skill::TEMPLATES[$templateKey])) {
            $available = implode(', ', array_keys(Skill::TEMPLATES));
            throw new RuntimeException("Template '{$templateKey}' not found. Available: {$available}.");
        }

        if (Skill::where('name', $name)->exists()) {
            throw new RuntimeException("A skill named '{$name}' already exists.");
        }

        $tpl = Skill::TEMPLATES[$templateKey];
        $category = (string) ($arguments['category'] ?? $tpl['category']);
        $description = trim((string) ($arguments['description'] ?? $tpl['description']));
        $instructions = trim((string) ($arguments['instructions'] ?? $tpl['instructions']));

        $skill = Skill::create([
            'name' => $name,
            'description' => $description,
            'category' => in_array($category, Skill::CATEGORIES, true) ? $category : $tpl['category'],
            'instructions' => $instructions,
            'is_active' => true,
            'created_by' => 'agent',
            'version' => 1,
            'template' => $templateKey,
        ]);

        $skill->snapshotVersion('agent', "Created from template '{$templateKey}'.");

        return "Skill '{$name}' created from template '{$templateKey}' (v1). You can customise it with action: update.";
    }
}
