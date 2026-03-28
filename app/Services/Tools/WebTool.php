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
        return 'Search the web, extract content from URLs, crawl websites, or map site structure using Tavily. Actions: search, extract, crawl, map, fetch.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['search', 'extract', 'crawl', 'map', 'fetch'],
                    'description' => 'search: find pages for a query. extract: get clean content from specific URLs. crawl: traverse a site and return page content. map: list all URLs found under a domain. fetch: raw HTTP get.',
                ],
                'query' => ['type' => 'string', 'description' => 'Search query (search action).'],
                'url' => ['type' => 'string', 'description' => 'URL or comma-separated URLs (extract, crawl, map, fetch actions).'],
                'max_results' => ['type' => 'integer', 'description' => 'Max results to return (default 5).'],
                'search_depth' => ['type' => 'string', 'enum' => ['basic', 'advanced'], 'description' => 'Search depth — basic (faster) or advanced (more thorough).'],
                'max_depth' => ['type' => 'integer', 'description' => 'Link traversal depth for crawl/map (default 1).'],
                'limit' => ['type' => 'integer', 'description' => 'Max pages for crawl/map (default 20).'],
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
                (int) ($arguments['max_results'] ?? 5),
                (string) ($arguments['search_depth'] ?? 'basic'),
            ),
            'extract' => $this->extract((string) ($arguments['url'] ?? '')),
            'crawl' => $this->crawl(
                (string) ($arguments['url'] ?? ''),
                (int) ($arguments['max_depth'] ?? 1),
                (int) ($arguments['limit'] ?? 20),
            ),
            'map' => $this->map(
                (string) ($arguments['url'] ?? ''),
                (int) ($arguments['max_depth'] ?? 1),
                (int) ($arguments['limit'] ?? 50),
            ),
            'fetch' => $this->fetch((string) ($arguments['url'] ?? '')),
            default => throw new RuntimeException('Unsupported web action. Use: search, extract, crawl, map, or fetch.'),
        };
    }

    private function apiKey(): string
    {
        $key = (string) config('services.tavily.api_key', '');

        if ($key === '') {
            throw new RuntimeException('TAVILY_API_KEY is missing. Set it in .env to use web features.');
        }

        return $key;
    }

    private function tavilyPost(string $endpoint, mixed $body): mixed
    {
        $response = Http::timeout(30)
            ->withToken($this->apiKey())
            ->acceptJson()
            ->post((string) config('services.tavily.base_url', 'https://api.tavily.com').$endpoint, $body);

        if ($response->failed()) {
            throw new RuntimeException("Tavily {$endpoint} failed: ".$response->body());
        }

        return $response->json();
    }

    private function search(string $query, int $maxResults, string $depth): string
    {
        if (trim($query) === '') {
            throw new RuntimeException('A search query is required.');
        }

        /** @var array<string, mixed> $data */
        $data = $this->tavilyPost('/search', [
            'query' => $query,
            'search_depth' => in_array($depth, ['basic', 'advanced'], true) ? $depth : 'basic',
            'max_results' => max(1, min(20, $maxResults)),
            'include_answer' => false,
        ]);

        $results = $data['results'] ?? [];

        if ($results === []) {
            return 'No results found.';
        }

        $formatted = collect($results)->values()->map(function (array $result, int $index): string {
            return trim(sprintf(
                "%d. %s\n   URL: %s\n   %s",
                $index + 1,
                $result['title'] ?? 'Untitled',
                $result['url'] ?? '',
                $result['content'] ?? 'No description.',
            ));
        })->implode("\n\n");

        return $this->truncate($formatted, 200);
    }

    private function extract(string $rawUrls): string
    {
        $urls = array_filter(array_map('trim', explode(',', $rawUrls)));

        if ($urls === []) {
            throw new RuntimeException('At least one URL is required for extract.');
        }

        /** @var array<string, mixed> $data */
        $data = $this->tavilyPost('/extract', [
            'urls' => array_values(array_slice($urls, 0, 20)),
            'extract_depth' => 'basic',
            'format' => 'markdown',
        ]);

        $results = $data['results'] ?? [];
        $failed = $data['failed_results'] ?? [];

        if ($results === [] && $failed !== []) {
            return 'Failed to extract content from the provided URLs.';
        }

        $output = collect($results)->map(function (array $result): string {
            $content = $result['raw_content'] ?? '';
            $truncated = mb_strlen($content) > 3000 ? mb_substr($content, 0, 3000).'…' : $content;

            return "=== {$result['url']} ===\n".trim($truncated);
        })->implode("\n\n");

        return $this->truncate($output, 400);
    }

    private function crawl(string $url, int $maxDepth, int $limit): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A valid URL is required for crawl.');
        }

        /** @var array<string, mixed> $data */
        $data = $this->tavilyPost('/crawl', [
            'url' => $url,
            'max_depth' => max(1, min(5, $maxDepth)),
            'limit' => max(1, min(50, $limit)),
            'format' => 'markdown',
        ]);

        $results = $data['results'] ?? [];

        if ($results === []) {
            return 'No pages found during crawl.';
        }

        $output = collect($results)->map(function (array $result): string {
            $content = $result['raw_content'] ?? '';
            $truncated = mb_strlen($content) > 1500 ? mb_substr($content, 0, 1500).'…' : $content;

            return "=== {$result['url']} ===\n".trim($truncated);
        })->implode("\n\n---\n\n");

        return $this->truncate($output, 500);
    }

    private function map(string $url, int $maxDepth, int $limit): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A valid URL is required for map.');
        }

        /** @var array<string, mixed> $data */
        $data = $this->tavilyPost('/map', [
            'url' => $url,
            'max_depth' => max(1, min(5, $maxDepth)),
            'limit' => max(1, min(100, $limit)),
        ]);

        $urls = $data['results'] ?? [];

        if ($urls === []) {
            return 'No URLs discovered.';
        }

        $list = collect($urls)->map(fn (string $u, int $i): string => ($i + 1).'. '.$u)->implode("\n");

        return 'Discovered '.count($urls)." URLs under {$url}:\n\n".$this->truncate($list, 300);
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
