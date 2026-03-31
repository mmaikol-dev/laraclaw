<?php

namespace App\Services\Tools;

use App\Models\AgentMemory;
use RuntimeException;

class MemoryTool extends BaseTool
{
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

        $expiresAt = isset($args['ttl_days']) ? now()->addDays((int) $args['ttl_days']) : null;

        AgentMemory::updateOrCreate(['key' => $key], [
            'value' => $value,
            'category' => (string) ($args['category'] ?? 'general'),
            'tags' => is_array($args['tags'] ?? null) ? $args['tags'] : null,
            'expires_at' => $expiresAt,
        ]);

        return "Memory '{$key}' saved.".($expiresAt ? " Expires: {$expiresAt->toDateString()}." : '');
    }

    private function get(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('key is required for get.');
        }

        $mem = AgentMemory::active()->where('key', $key)->first();

        if ($mem === null) {
            return "No memory found for key '{$key}'.";
        }

        return "[{$mem->category}] {$mem->key}: {$mem->value}".
            ($mem->expires_at ? " (expires {$mem->expires_at->toDateString()})" : '');
    }

    private function search(string $query): string
    {
        if ($query === '') {
            throw new RuntimeException('query is required for search.');
        }

        $memories = AgentMemory::active()
            ->where(fn ($q) => $q->where('key', 'like', "%{$query}%")->orWhere('value', 'like', "%{$query}%"))
            ->get();

        if ($memories->isEmpty()) {
            return "No memories found matching '{$query}'.";
        }

        $lines = $memories->map(fn ($m) => "  [{$m->category}] {$m->key}: {$m->value}")->implode("\n");

        return "Found {$memories->count()} memories:\n\n{$lines}";
    }

    private function list(?string $category): string
    {
        $query = AgentMemory::active()->orderBy('category')->orderBy('key');

        if ($category) {
            $query->where('category', $category);
        }

        $memories = $query->get();

        if ($memories->isEmpty()) {
            return 'No memories stored yet.';
        }

        $lines = $memories->map(fn ($m) => "  [{$m->category}] {$m->key}: ".str($m->value)->limit(120)
        )->implode("\n");

        return "Memories ({$memories->count()}):\n\n{$lines}";
    }

    private function delete(string $key): string
    {
        if ($key === '') {
            throw new RuntimeException('key is required for delete.');
        }

        $deleted = AgentMemory::where('key', $key)->delete();

        return $deleted ? "Memory '{$key}' deleted." : "Memory '{$key}' not found.";
    }

    private function clearExpired(): string
    {
        $count = AgentMemory::where('expires_at', '<', now())->delete();

        return "Cleared {$count} expired memories.";
    }
}
