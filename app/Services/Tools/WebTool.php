<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebTool extends BaseTool
{
    public function getName(): string
    {
        return 'web';
    }

    public function getDescription(): string
    {
        return 'Search the web with Brave Search or fetch and clean remote page content for the agent to read.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string'],
                'query' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'max_results' => ['type' => 'integer'],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_web', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'search' => $this->search(
                (string) ($arguments['query'] ?? ''),
                (int) ($arguments['max_results'] ?? config('services.brave.max_results', 5)),
            ),
            'fetch' => $this->fetch((string) ($arguments['url'] ?? '')),
            default => throw new RuntimeException('Unsupported web action.'),
        };
    }

    private function search(string $query, int $maxResults): string
    {
        $apiKey = (string) config('services.brave.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('BRAVE_API_KEY is missing. Set it in .env before using web search.');
        }

        $response = Http::timeout(15)
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Subscription-Token' => $apiKey,
            ])->get((string) config('services.brave.endpoint'), [
                'q' => $query,
                'count' => max(1, min(10, $maxResults)),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Brave search failed: '.$response->body());
        }

        $results = $response->json('web.results') ?? [];

        if ($results === []) {
            return 'No results found.';
        }

        $formatted = collect($results)->values()->map(function (array $result, int $index): string {
            $published = $result['page_age'] ?? $result['age'] ?? null;

            return trim(sprintf(
                "%d. %s\n   URL: %s\n   %s%s",
                $index + 1,
                $result['title'] ?? 'Untitled',
                $result['url'] ?? '',
                $result['description'] ?? 'No description provided.',
                $published ? "\n   Published: {$published}" : '',
            ));
        })->implode("\n\n");

        return $this->truncate($formatted, 200);
    }

    private function fetch(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A valid URL is required.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($this->isBlockedHost($host)) {
            throw new RuntimeException('Fetching local or private hosts is not allowed.');
        }

        $response = Http::timeout(20)
            ->withHeaders(['User-Agent' => 'LaraClaw-Agent/1.0'])
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException('Web fetch failed: '.$response->body());
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        $body = $response->body();

        if (str_contains($contentType, 'html')) {
            $body = preg_replace('/<(script|style|nav|footer)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
            $body = preg_replace('/<(p|div|h[1-6]|li|br)\b[^>]*>/i', "\n", $body) ?? $body;
            $body = strip_tags($body);
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
            $body = preg_replace("/[ \t]+/", ' ', $body) ?? $body;
            $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
        }

        return $this->truncate("=== Content from {$url} ===\n".trim($body), 300);
    }

    private function isBlockedHost(string $host): bool
    {
        return $host === 'localhost'
            || $host === '::1'
            || $host === '0.0.0.0'
            || preg_match('/^127\./', $host) === 1
            || preg_match('/^10\./', $host) === 1
            || preg_match('/^192\.168\./', $host) === 1
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host) === 1;
    }
}
