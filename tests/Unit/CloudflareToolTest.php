<?php

namespace Tests\Unit;

use App\Services\Agent\ToolRegistry;
use App\Services\Tools\CloudflareTool;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CloudflareToolTest extends TestCase
{
    public function test_the_cloudflare_tool_is_registered(): void
    {
        $this->assertInstanceOf(CloudflareTool::class, app(ToolRegistry::class)->get('cloudflare'));
    }

    public function test_it_verifies_the_api_token(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
                'success' => true,
                'result' => ['status' => 'active'],
            ]),
        ]);

        $result = (new CloudflareTool)->execute([
            'action' => 'verify',
            'api_token' => 'cf-token',
        ]);

        $this->assertStringContainsString('active', $result);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/user/tokens/verify')
            && $request->hasHeader('Authorization', 'Bearer cf-token'));
    }

    public function test_it_lists_zones(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'zone-1',
                    'name' => 'example.com',
                    'status' => 'active',
                    'plan' => ['name' => 'Pro'],
                ]],
            ]),
        ]);

        $result = (new CloudflareTool)->execute([
            'action' => 'list_zones',
            'api_token' => 'cf-token',
        ]);

        $this->assertStringContainsString('example.com', $result);
        $this->assertStringContainsString('zone-1', $result);
    }

    public function test_it_creates_a_dns_record(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
                'success' => true,
                'result' => ['id' => 'rec-1', 'name' => 'app.example.com', 'type' => 'A', 'content' => '203.0.113.10'],
            ]),
        ]);

        $result = (new CloudflareTool)->execute([
            'action' => 'create_dns',
            'api_token' => 'cf-token',
            'zone_id' => 'zone-1',
            'name' => 'app.example.com',
            'type' => 'a',
            'content' => '203.0.113.10',
            'proxied' => true,
        ]);

        $this->assertStringContainsString('rec-1', $result);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/zones/zone-1/dns_records')) {
                return false;
            }

            return $request['name'] === 'app.example.com'
                && $request['type'] === 'A'
                && $request['content'] === '203.0.113.10'
                && $request['proxied'] === true;
        });
    }

    public function test_it_lists_tunnels_for_an_account(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts/acc-1/cfd_tunnel' => Http::response([
                'success' => true,
                'result' => [[
                    'id' => 'tunnel-1',
                    'name' => 'laraclaw',
                    'status' => 'healthy',
                    'connections' => [['id' => 'c1']],
                ]],
            ]),
        ]);

        $result = (new CloudflareTool)->execute([
            'action' => 'list_tunnels',
            'api_token' => 'cf-token',
            'account_id' => 'acc-1',
        ]);

        $this->assertStringContainsString('laraclaw', $result);
        $this->assertStringContainsString('connections: 1', $result);
    }

    public function test_it_throws_when_cloudflare_reports_an_error(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
            ], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authentication error');

        (new CloudflareTool)->execute([
            'action' => 'verify',
            'api_token' => 'bad-token',
        ]);
    }

    public function test_it_requires_an_api_token(): void
    {
        $this->expectException(RuntimeException::class);

        (new CloudflareTool)->execute([
            'action' => 'verify',
        ]);
    }
}
