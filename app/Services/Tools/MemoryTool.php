<?php

namespace App\Services\Tools;

use App\Models\AgentMemory;
use App\Models\AgentRoleProfile;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class MemoryTool extends BaseTool
{
    /**
     * Scopes the shared scopes that stay visible to every employee.
     *
     * @var array<int, string>
     */
    private const SHARED_SCOPES = ['global', 'user', 'environment'];

    public function __construct(private readonly ?AgentRoleProfile $role = null) {}

    /**
     * The employee namespace prefix, built here rather than supplied by the model.
     *
     * A model-supplied prefix would let one employee write into another's
     * namespace, which is the exact leak the role scope exists to prevent.
     */
    private function prefix(): string
    {
        return $this->role === null ? '' : 'role:'.$this->role->slug.':';
    }

    /**
     * Strip any namespace the model tried to smuggle into the key.
     */
    private function sanitiseKey(string $key): string
    {
        return trim(str_replace(':', '', $key));
    }

    /**
     * Restrict reads to this employee's own memories plus the shared ones.
     *
     * @param  Builder<AgentMemory>  $query
     * @return Builder<AgentMemory>
     */
    private function visible(Builder $query): Builder
    {
        if ($this->role === null) {
            return $query;
        }

        return $query->where(function (Builder $q): void {
            $q->whereIn('scope', self::SHARED_SCOPES)
                ->orWhere(function (Builder $own) {
                    $own
                        ->where('scope', 'role')
                        ->where('subject_type', AgentRoleProfile::class)
                        ->where('subject_id', $this->role->id);
                })
                ->orWhere('key', 'like', $this->prefix().'%');
        });
    }

    public function getName(): string
    {
        return 'memory';
    }

    public function getDescription(): string
    {
        return 'Persist information across conversations. Store facts, decisions, context, and notes that should be remembered in future sessions. Use this proactively to remember anything important.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['set', 'get', 'search', 'list', 'delete', 'clear_expired'],
                    'description' => implode(' ', [
                        'set: store a value under a key.',
                        'get: retrieve a value by key.',
                        'search: find memories by keyword in key or value.',
                        'list: list all memories, optionally filtered by category.',
                        'delete: remove a memory by key.',
                        'clear_expired: delete all expired memories.',
                    ]),
                ],
                'key' => ['type' => 'string', 'description' => 'Memory key (required for set/get/delete).'],
                'value' => ['type' => 'string', 'description' => 'Value to store (required for set).'],
                'category' => ['type' => 'string', 'description' => 'Category tag e.g. "user", "project", "fact" (set/list).'],
                'scope' => ['type' => 'string', 'description' => 'Memory scope: global, user, project, environment, workflow, conversation.'],
                'source' => ['type' => 'string', 'description' => 'Where this memory came from, e.g. user, agent-run, environment-monitor.'],
                'confidence' => ['type' => 'number', 'description' => 'Confidence from 0.0 to 1.0.'],
                'tags' => ['type' => 'array', 'description' => 'Optional string tags for set action.'],
                'ttl_days' => ['type' => 'integer', 'description' => 'Days until this memory expires (set). Omit for permanent.'],
                'query' => ['type' => 'string', 'description' => 'Keyword to search for (search).'],
            ],
            'required' => ['action'],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'set' => $this->set($arguments),
            'get' => $this->get((string) ($arguments['key'] ?? '')),
            'search' => $this->search((string) ($arguments['query'] ?? '')),
            'list' => $this->list($arguments['category'] ?? null),
            'delete' => $this->delete((string) ($arguments['key'] ?? '')),
            'clear_expired' => $this->clearExpired(),
            default => throw new RuntimeException('Unsupported memory action.'),
        };
    }

    /** @param array<string, mixed> $args */
    private function set(array $args): string
    {
        $key = trim((string) ($args['key'] ?? ''));
        $value = trim((string) ($args['value'] ?? ''));

        if ($key === '' || $value === '') {
            throw new RuntimeException('key and value are required for set.');
        }

        $key = $this->sanitiseKey($key);

        if ($key === '') {
            throw new RuntimeException('key is required for set.');
        }

        $expiresAt = isset($args['ttl_days']) ? now()->addDays((int) $args['ttl_days']) : null;

        $scope = (string) ($args['scope'] ?? 'global');

        // A bound employee owns its experience unless the agent explicitly asks
        // for a shared scope; defaulting to global would strand every write in
        // a pool no single employee reads as its own.
        $requestedShared = isset($args['scope']) && in_array($scope, self::SHARED_SCOPES, true);
        $isOwnNamespace = $this->role !== null && ! $requestedShared;
        $storedKey = $isOwnNamespace ? $this->prefix().$key : $key;

        AgentMemory::updateOrCreate(['key' => $storedKey], [
            'value' => $value,
            'category' => (string) ($args['category'] ?? 'general'),
            'scope' => $isOwnNamespace ? 'role' : $scope,
            'subject_type' => $isOwnNamespace ? AgentRoleProfile::class : null,
            'subject_id' => $isOwnNamespace ? $this->role->id : null,
            'source' => (string) ($args['source'] ?? 'agent'),
            'confidence' => min(1, max(0, (float) ($args['confidence'] ?? 1))),
            'tags' => is_array($args['tags'] ?? null) ? $args['tags'] : null,
            'expires_at' => $expiresAt,
            'last_observed_at' => now(),
        ]);

        return "Memory '{$storedKey}' saved.".($expiresAt ? " Expires: {$expiresAt->toDateString()}." : '');
    }

    private function get(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('key is required for get.');
        }

        $mem = $this->visible(AgentMemory::active())->where('key', $key)->first();

        if ($mem === null) {
            return "No memory found for key '{$key}'.";
        }

        return "[{$mem->scope}/{$mem->category}] {$mem->key}: {$mem->value}".
            ($mem->expires_at ? " (expires {$mem->expires_at->toDateString()})" : '');
    }

    private function search(string $query): string
    {
        if ($query === '') {
            throw new RuntimeException('query is required for search.');
        }

        $memories = $this->visible(AgentMemory::active())
            ->where(fn ($q) => $q->where('key', 'like', "%{$query}%")->orWhere('value', 'like', "%{$query}%"))
            ->get();

        if ($memories->isEmpty()) {
            return "No memories found matching '{$query}'.";
        }

        $lines = $memories->map(fn ($m) => "  [{$m->scope}/{$m->category}] {$m->key}: {$m->value}")->implode("\n");

        return "Found {$memories->count()} memories:\n\n{$lines}";
    }

    private function list(?string $category): string
    {
        $query = $this->visible(AgentMemory::active())->orderBy('category')->orderBy('key');

        if ($category) {
            $query->where('category', $category);
        }

        $memories = $query->get();

        if ($memories->isEmpty()) {
            return 'No memories stored yet.';
        }

        $lines = $memories->map(fn ($m) => "  [{$m->scope}/{$m->category}] {$m->key}: ".str($m->value)->limit(120)
        )->implode("\n");

        return "Memories ({$memories->count()}):\n\n{$lines}";
    }

    private function delete(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('key is required for delete.');
        }

        $deleted = $this->visible(AgentMemory::query())->where('key', $key)->delete();

        return $deleted ? "Memory '{$key}' deleted." : "Memory '{$key}' not found.";
    }

    private function clearExpired(): string
    {
        $count = AgentMemory::where('expires_at', '<', now())->delete();

        return "Cleared {$count} expired memories.";
    }
}
