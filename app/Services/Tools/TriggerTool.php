<?php

namespace App\Services\Tools;

use App\Models\Trigger;
use Illuminate\Support\Str;
use RuntimeException;

class TriggerTool extends BaseTool
{
    public function getName(): string
    {
        return 'trigger';
    }

    public function getDescription(): string
    {
        return 'Create proactive triggers — watch folders for new files, monitor URLs for changes, or receive webhooks — and automatically run the agent when they fire.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'create', 'update', 'delete', 'pause', 'resume', 'info'],
                    'description' => implode(' ', [
                        'list: show all triggers.',
                        'create: define a new trigger.',
                        'update: modify an existing trigger.',
                        'delete: remove a trigger.',
                        'pause/resume: enable or disable.',
                        'info: show full config for a trigger including webhook URL.',
                    ]),
                ],
                'name' => ['type' => 'string', 'description' => 'Trigger name.'],
                'description' => ['type' => 'string', 'description' => 'What this trigger watches for.'],
                'type' => [
                    'type' => 'string',
                    'enum' => ['file_watcher', 'webhook', 'url_monitor'],
                    'description' => 'file_watcher: watch a directory for new/changed files. webhook: receive HTTP POST. url_monitor: poll a URL and trigger when content changes.',
                ],
                'prompt' => ['type' => 'string', 'description' => 'Prompt to run when this trigger fires.'],
                'directory' => ['type' => 'string', 'description' => 'Directory to watch (file_watcher).'],
                'pattern' => ['type' => 'string', 'description' => 'Glob pattern e.g. "*.csv" (file_watcher, default *).'],
                'url' => ['type' => 'string', 'description' => 'URL to monitor (url_monitor).'],
                'webhook_secret' => ['type' => 'string', 'description' => 'Optional secret for webhook verification.'],
            ],
            'required' => ['action'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'list' => $this->list(),
            'create' => $this->create($arguments),
            'update' => $this->update($arguments),
            'delete' => $this->delete((string) ($arguments['name'] ?? '')),
            'pause' => $this->setActive((string) ($arguments['name'] ?? ''), false),
            'resume' => $this->setActive((string) ($arguments['name'] ?? ''), true),
            'info' => $this->info((string) ($arguments['name'] ?? '')),
            default => throw new RuntimeException('Unsupported trigger action.'),
        };
    }

    private function list(): string
    {
        $triggers = Trigger::orderBy('name')->get();

        if ($triggers->isEmpty()) {
            return 'No triggers defined yet.';
        }

        $lines = $triggers->map(fn ($t) => sprintf(
            '[%s] %s (%s) — last fired: %s',
            $t->is_active ? '✓' : '✗',
            $t->name,
            $t->type,
            $t->last_triggered_at?->diffForHumans() ?? 'never',
        ))->implode("\n");

        return "Triggers ({$triggers->count()}):\n\n{$lines}";
    }

    /** @param array<string, mixed> $args */
    private function create(array $args): string
    {
        $name = trim((string) ($args['name'] ?? ''));
        $type = (string) ($args['type'] ?? '');
        $prompt = trim((string) ($args['prompt'] ?? ''));

        if ($name === '' || $type === '' || $prompt === '') {
            throw new RuntimeException('name, type, and prompt are required.');
        }

        if (Trigger::where('name', $name)->exists()) {
            throw new RuntimeException("Trigger '{$name}' already exists.");
        }

        $config = match ($type) {
            'file_watcher' => [
                'directory' => (string) ($args['directory'] ?? ''),
                'pattern' => (string) ($args['pattern'] ?? '*'),
            ],
            'url_monitor' => ['url' => (string) ($args['url'] ?? '')],
            'webhook' => [],
            default => throw new RuntimeException("Unknown type '{$type}'. Use: file_watcher, webhook, url_monitor."),
        };

        if ($type === 'file_watcher' && empty($config['directory'])) {
            throw new RuntimeException('directory is required for file_watcher.');
        }

        if ($type === 'url_monitor' && empty($config['url'])) {
            throw new RuntimeException('url is required for url_monitor.');
        }

        Trigger::create([
            'name' => $name,
            'description' => $args['description'] ?? null,
            'type' => $type,
            'config' => $config,
            'prompt' => $prompt,
            'is_active' => true,
            'webhook_secret' => $args['webhook_secret'] ?? ($type === 'webhook' ? Str::random(32) : null),
        ]);

        $extra = $type === 'webhook' ? "\nWebhook URL: ".url("/api/webhooks/{$name}") : '';

        return "Trigger '{$name}' ({$type}) created.{$extra}";
    }

    /** @param array<string, mixed> $args */
    private function update(array $args): string
    {
        $trigger = $this->findOrFail((string) ($args['name'] ?? ''));

        $fields = array_filter([
            'description' => $args['description'] ?? null,
            'prompt' => $args['prompt'] ?? null,
        ], fn ($v) => $v !== null);

        // Update config fields
        if (isset($args['directory']) || isset($args['pattern'])) {
            $config = $trigger->config;
            if (isset($args['directory'])) {
                $config['directory'] = $args['directory'];
            }
            if (isset($args['pattern'])) {
                $config['pattern'] = $args['pattern'];
            }
            $fields['config'] = $config;
        }

        if (isset($args['url'])) {
            $config = $trigger->config;
            $config['url'] = $args['url'];
            $fields['config'] = $config;
        }

        if ($fields === []) {
            throw new RuntimeException('No fields to update.');
        }

        $trigger->update($fields);

        return "Trigger '{$trigger->name}' updated.";
    }

    private function delete(string $name): string
    {
        $this->findOrFail($name)->delete();

        return "Trigger '{$name}' deleted.";
    }

    private function setActive(string $name, bool $active): string
    {
        $this->findOrFail($name)->update(['is_active' => $active]);

        return "Trigger '{$name}' ".($active ? 'resumed' : 'paused').'.';
    }

    private function info(string $name): string
    {
        $t = $this->findOrFail($name);
        $config = json_encode($t->config, JSON_PRETTY_PRINT) ?: '{}';
        $webhook = $t->type === 'webhook'
            ? "\nWebhook URL: ".url("/api/webhooks/{$t->name}").
              ($t->webhook_secret ? "\nSecret header: X-Webhook-Secret: {$t->webhook_secret}" : '')
            : '';

        $status = $t->is_active ? 'active' : 'paused';
        $lastFired = $t->last_triggered_at?->toDateTimeString() ?? 'never';

        return <<<OUT
=== {$t->name} ===
Type: {$t->type}
Status: {$status}
Last fired: {$lastFired}
Config: {$config}{$webhook}

Prompt: {$t->prompt}
OUT;
    }

    private function findOrFail(string $name): Trigger
    {
        if ($name === '') {
            throw new RuntimeException('name is required.');
        }

        $trigger = Trigger::where('name', $name)->first();

        if ($trigger === null) {
            throw new RuntimeException("Trigger '{$name}' not found.");
        }

        return $trigger;
    }
}
