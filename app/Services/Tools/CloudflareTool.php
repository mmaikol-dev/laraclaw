<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareTool extends BaseTool
{
    private string $apiToken = '';

    public function getName(): string
    {
        return 'cloudflare';
    }

    public function getDescription(): string
    {
        return 'Manage Cloudflare for the sites we host: verify the API token, list accounts and zones, inspect a zone, list/create/update/delete DNS records, purge the cache, and manage Cloudflare Tunnels (list, inspect, fetch the connector token). Actions: verify, list_accounts, list_zones, zone, list_dns, create_dns, update_dns, delete_dns, purge_cache, list_tunnels, get_tunnel, tunnel_token. Pass api_token (and account_id for tunnel actions) from your stored credentials (memory) with every call.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'verify', 'list_accounts', 'list_zones', 'zone', 'list_dns',
                        'create_dns', 'update_dns', 'delete_dns', 'purge_cache',
                        'list_tunnels', 'get_tunnel', 'tunnel_token',
                    ],
                    'description' => 'The Cloudflare operation to perform.',
                ],
                'api_token' => ['type' => 'string', 'description' => 'Cloudflare API token. Recall the stored token from your memory and pass it here each call.'],
                'account_id' => ['type' => 'string', 'description' => 'Cloudflare account ID (list_tunnels, get_tunnel, tunnel_token).'],
                'zone_id' => ['type' => 'string', 'description' => 'Zone ID (zone, list_dns, create_dns, update_dns, delete_dns, purge_cache).'],
                'tunnel_id' => ['type' => 'string', 'description' => 'Tunnel ID (get_tunnel, tunnel_token).'],
                'record_id' => ['type' => 'string', 'description' => 'DNS record ID (update_dns, delete_dns).'],
                'name' => ['type' => 'string', 'description' => 'DNS record name, e.g. app.example.com (list_dns filter, create_dns, update_dns).'],
                'type' => ['type' => 'string', 'description' => 'DNS record type, e.g. A, AAAA, CNAME, TXT (create_dns, update_dns; optional filter for list_dns).'],
                'content' => ['type' => 'string', 'description' => 'DNS record content/value, e.g. an IP or target hostname (create_dns, update_dns).'],
                'ttl' => ['type' => 'integer', 'description' => 'DNS record TTL in seconds; 1 means automatic (create_dns, update_dns).'],
                'proxied' => ['type' => 'boolean', 'description' => 'Whether the DNS record is proxied through Cloudflare (create_dns, update_dns).'],
                'comment' => ['type' => 'string', 'description' => 'Optional comment to attach to a DNS record.'],
                'files' => ['type' => 'string', 'description' => 'purge_cache: comma-separated URLs to purge.'],
                'everything' => ['type' => 'boolean', 'description' => 'purge_cache: true to purge the entire cache.'],
            ],
            'required' => ['action', 'api_token'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_cloudflare', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $this->apiToken = $this->resolveApiToken($arguments);

        return match ($arguments['action'] ?? null) {
            'verify' => $this->verify(),
            'list_accounts' => $this->listAccounts(),
            'list_zones' => $this->listZones($this->string($arguments, 'name')),
            'zone' => $this->zone($this->required($arguments, 'zone_id')),
            'list_dns' => $this->listDns(
                $this->required($arguments, 'zone_id'),
                $this->string($arguments, 'name'),
                $this->string($arguments, 'type'),
            ),
            'create_dns' => $this->createDns($arguments),
            'update_dns' => $this->updateDns($arguments),
            'delete_dns' => $this->deleteDns(
                $this->required($arguments, 'zone_id'),
                $this->required($arguments, 'record_id'),
            ),
            'purge_cache' => $this->purgeCache($arguments),
            'list_tunnels' => $this->listTunnels($this->required($arguments, 'account_id')),
            'get_tunnel' => $this->getTunnel(
                $this->required($arguments, 'account_id'),
                $this->required($arguments, 'tunnel_id'),
            ),
            'tunnel_token' => $this->tunnelToken(
                $this->required($arguments, 'account_id'),
                $this->required($arguments, 'tunnel_id'),
            ),
            default => throw new RuntimeException('Unsupported cloudflare action. Use one of: verify, list_accounts, list_zones, zone, list_dns, create_dns, update_dns, delete_dns, purge_cache, list_tunnels, get_tunnel, tunnel_token.'),
        };
    }

    private function verify(): string
    {
        $result = $this->request('GET', '/user/tokens/verify');

        $status = $result['result']['status'] ?? 'unknown';

        return "Cloudflare token status: {$status}.";
    }

    private function listAccounts(): string
    {
        $result = $this->request('GET', '/accounts');

        return $this->formatCollection($result, 'accounts', fn (array $account): string => ($account['name'] ?? '(unknown)').' (ID: '.($account['id'] ?? '?').', type: '.($account['type'] ?? 'unknown').')');
    }

    private function listZones(string $name): string
    {
        $query = $name !== '' ? ['name' => $name] : [];
        $result = $this->request('GET', '/zones', $query);

        return $this->formatCollection($result, 'zones', fn (array $zone): string => ($zone['name'] ?? '(unknown)').' (ID: '.($zone['id'] ?? '?').', status: '.($zone['status'] ?? 'unknown').', plan: '.($zone['plan']['name'] ?? 'unknown').')');
    }

    private function zone(string $zoneId): string
    {
        $result = $this->request('GET', "/zones/{$zoneId}");
        $zone = $result['result'] ?? [];

        if (! is_array($zone) || $zone === []) {
            return 'Zone not found.';
        }

        $lines = [
            'Zone: '.($zone['name'] ?? '(unknown)'),
            'ID: '.($zone['id'] ?? '(unknown)'),
            'Status: '.($zone['status'] ?? 'unknown'),
            'Plan: '.($zone['plan']['name'] ?? 'unknown'),
        ];

        if (! empty($zone['name_servers'])) {
            $lines[] = 'Nameservers: '.implode(', ', $zone['name_servers']);
        }

        return implode("\n", $lines);
    }

    private function listDns(string $zoneId, string $name, string $type): string
    {
        $query = [];

        if ($name !== '') {
            $query['name'] = $name;
        }

        if ($type !== '') {
            $query['type'] = $type;
        }

        $result = $this->request('GET', "/zones/{$zoneId}/dns_records", $query);

        return $this->formatCollection($result, 'DNS records', fn (array $record): string => $this->formatDnsRecord($record));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function createDns(array $arguments): string
    {
        $payload = $this->dnsPayload($arguments);

        $result = $this->request('POST', '/zones/'.$this->required($arguments, 'zone_id').'/dns_records', $payload);
        $record = $result['result'] ?? [];

        return 'DNS record created. ID: '.($record['id'] ?? '(unknown)');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function updateDns(array $arguments): string
    {
        $zoneId = $this->required($arguments, 'zone_id');
        $recordId = $this->required($arguments, 'record_id');
        $payload = $this->dnsPayload($arguments);

        $result = $this->request('PUT', "/zones/{$zoneId}/dns_records/{$recordId}", $payload);
        $record = $result['result'] ?? [];

        return 'DNS record updated. ID: '.($record['id'] ?? $recordId);
    }

    private function deleteDns(string $zoneId, string $recordId): string
    {
        $this->request('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");

        return "DNS record {$recordId} deleted.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function purgeCache(array $arguments): string
    {
        $payload = [];

        if (filter_var($arguments['everything'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $payload = ['purge_everything' => true];
        } else {
            $files = collect(explode(',', $this->string($arguments, 'files')))
                ->map(fn (string $file): string => trim($file))
                ->filter(fn (string $file): bool => $file !== '')
                ->values()
                ->all();

            if ($files === []) {
                throw new RuntimeException('purge_cache needs either everything=true or a comma-separated list of files (URLs) to purge.');
            }

            $payload = ['files' => $files];
        }

        $this->request('POST', '/zones/'.$this->required($arguments, 'zone_id').'/purge_cache', $payload);

        return 'Cache purged.';
    }

    private function listTunnels(string $accountId): string
    {
        $result = $this->request('GET', "/accounts/{$accountId}/cfd_tunnel");

        return $this->formatCollection($result, 'tunnels', function (array $tunnel): string {
            $connections = is_array($tunnel['connections'] ?? null) ? count($tunnel['connections']) : 0;

            return ($tunnel['name'] ?? '(unknown)').' (ID: '.($tunnel['id'] ?? '?').', status: '.($tunnel['status'] ?? 'unknown').", connections: {$connections})";
        });
    }

    private function getTunnel(string $accountId, string $tunnelId): string
    {
        $result = $this->request('GET', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        $tunnel = $result['result'] ?? [];

        if (! is_array($tunnel) || $tunnel === []) {
            return 'Tunnel not found.';
        }

        $connections = is_array($tunnel['connections'] ?? null) ? count($tunnel['connections']) : 0;

        $lines = [
            'Tunnel: '.($tunnel['name'] ?? '(unknown)'),
            'ID: '.($tunnel['id'] ?? '(unknown)'),
            'Status: '.($tunnel['status'] ?? 'unknown'),
            "Connections: {$connections}",
        ];

        if (! empty($tunnel['created_at'])) {
            $lines[] = 'Created: '.$tunnel['created_at'];
        }

        return implode("\n", $lines);
    }

    private function tunnelToken(string $accountId, string $tunnelId): string
    {
        $result = $this->request('GET', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token");
        $token = $result['result'] ?? '';

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Cloudflare did not return a tunnel token for this tunnel.');
        }

        return "Tunnel connector token:\n{$token}";
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function dnsPayload(array $arguments): array
    {
        $payload = [
            'name' => $this->required($arguments, 'name'),
            'type' => strtoupper($this->required($arguments, 'type')),
            'content' => $this->required($arguments, 'content'),
        ];

        if (array_key_exists('ttl', $arguments) && $arguments['ttl'] !== null) {
            $payload['ttl'] = (int) $arguments['ttl'];
        }

        if (array_key_exists('proxied', $arguments) && $arguments['proxied'] !== null) {
            $payload['proxied'] = (bool) $arguments['proxied'];
        }

        $comment = $this->string($arguments, 'comment');

        if ($comment !== '') {
            $payload['comment'] = $comment;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveApiToken(array $arguments): string
    {
        $token = trim((string) ($arguments['api_token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('A Cloudflare API token is required. Pass api_token (recall the stored token from your memory).');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function required(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("A {$key} is required for this cloudflare action.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function string(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $base = rtrim((string) config('services.cloudflare.api_url', 'https://api.cloudflare.com/client/v4'), '/');
        $url = $base.'/'.ltrim($path, '/');
        $http = Http::withToken($this->apiToken)->acceptJson()->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $payload),
            'POST' => $http->post($url, $payload),
            'PUT' => $http->put($url, $payload),
            'DELETE' => $http->delete($url, $payload),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}."),
        };

        $data = $response->json();

        if ($response->failed() || (is_array($data) && ($data['success'] ?? true) === false)) {
            $message = $this->errorMessage($data, $response->body());

            throw new RuntimeException("Cloudflare API request failed: {$message}");
        }

        return is_array($data) ? $data : [];
    }

    private function errorMessage(mixed $data, string $fallback): string
    {
        if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $messages = collect($data['errors'])
                ->map(fn ($error): string => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error)
                ->filter()
                ->implode('; ');

            if ($messages !== '') {
                return $messages;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatCollection(array $data, string $label, callable $formatter): string
    {
        $items = $data['result'] ?? [];

        if (! is_array($items) || $items === []) {
            return "No {$label} found.";
        }

        $output = collect($items)->map(fn (array $item): string => $formatter($item))->implode("\n");

        return $this->truncate($output, 500);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function formatDnsRecord(array $record): string
    {
        $proxied = ! empty($record['proxied']) ? 'proxied' : 'dns-only';
        $ttl = $record['ttl'] ?? 'auto';

        return ($record['type'] ?? '?').' '.($record['name'] ?? '?').' -> '.($record['content'] ?? '?')
            .' (ID: '.($record['id'] ?? '?').", ttl: {$ttl}, {$proxied})";
    }
}
