<?php

namespace Tests\Unit;

use App\Models\MediaAsset;
use App\Services\Agent\ToolRegistry;
use App\Services\Tools\FacebookTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FacebookToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_facebook_tool_is_registered(): void
    {
        $this->assertInstanceOf(FacebookTool::class, app(ToolRegistry::class)->get('facebook'));
    }

    public function test_it_lists_page_posts(): void
    {
        Http::fake([
            'graph.facebook.com/*/page-1/posts*' => Http::response([
                'data' => [[
                    'id' => 'post-1',
                    'message' => 'Hello world',
                    'created_time' => '2026-01-01T00:00:00+0000',
                    'permalink_url' => 'https://facebook.com/post-1',
                    'comments' => ['summary' => ['total_count' => 3]],
                    'reactions' => ['summary' => ['total_count' => 5]],
                ]],
                'paging' => ['cursors' => ['after' => 'CURSOR123']],
            ]),
        ]);

        $result = (new FacebookTool)->execute([
            'action' => 'list_posts',
            'access_token' => 'test-facebook-token',
            'page_id' => 'page-1',
        ]);

        $this->assertStringContainsString('Post ID: post-1', $result);
        $this->assertStringContainsString('Message: Hello world', $result);
        $this->assertStringContainsString('Comments: 3', $result);
        $this->assertStringContainsString('Reactions: 5', $result);
        $this->assertStringContainsString('Next cursor (after): CURSOR123', $result);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/page-1/posts')
            && $request->hasHeader('Authorization', 'Bearer test-facebook-token'));

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/me/accounts'));
    }

    public function test_it_exchanges_the_system_token_for_a_page_token_before_writing(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1', 'access_token' => 'page-token-1']],
            ]),
            'graph.facebook.com/*/page-1/feed*' => Http::response(['id' => '123_456']),
        ]);

        (new FacebookTool)->execute([
            'action' => 'create_post',
            'access_token' => 'system-user-token',
            'page_id' => 'page-1',
            'message' => 'Hi',
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/me/accounts')
            && $request->hasHeader('Authorization', 'Bearer system-user-token'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/page-1/feed')
            && $request->hasHeader('Authorization', 'Bearer page-token-1'));
    }

    public function test_it_publishes_a_page_post(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1', 'access_token' => 'page-token-1']],
            ]),
            'graph.facebook.com/*/page-1/feed*' => Http::response(['id' => '123_456']),
        ]);

        $result = (new FacebookTool)->execute([
            'action' => 'create_post',
            'access_token' => 'test-facebook-token',
            'page_id' => 'page-1',
            'message' => 'Launch day!',
            'link' => 'https://example.com',
        ]);

        $this->assertStringContainsString('123_456', $result);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/page-1/feed')
            && $request['message'] === 'Launch day!'
            && $request['link'] === 'https://example.com'
            && $request->hasHeader('Authorization', 'Bearer page-token-1'));
    }

    public function test_publishing_a_post_marks_the_named_clip_as_posted(): void
    {
        $asset = MediaAsset::factory()->create(['slug' => 'launch-clip', 'status' => MediaAsset::STATUS_UNPOSTED]);

        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1', 'access_token' => 'page-token-1']],
            ]),
            'graph.facebook.com/*/page-1/feed*' => Http::response(['id' => '123_456']),
        ]);

        (new FacebookTool)->execute([
            'action' => 'create_post',
            'access_token' => 'test-facebook-token',
            'page_id' => 'page-1',
            'message' => 'Launch day!',
            'asset' => 'launch-clip',
        ]);

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_POSTED, $asset->status);
        $this->assertSame('facebook', $asset->platform);
        $this->assertSame('123_456', $asset->external_id);
        $this->assertSame('https://www.facebook.com/123_456', $asset->external_url);
    }

    public function test_it_fails_when_the_page_token_cannot_be_obtained(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['id' => 'other-page', 'access_token' => 'other-token']],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not obtain a Page Access Token for page page-1');

        (new FacebookTool)->execute([
            'action' => 'create_post',
            'access_token' => 'system-user-token',
            'page_id' => 'page-1',
            'message' => 'Hi',
        ]);
    }

    public function test_it_replies_to_a_comment(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1', 'access_token' => 'page-token-1']],
            ]),
            'graph.facebook.com/*/comment-1/comments*' => Http::response(['id' => 'reply-1']),
        ]);

        $result = (new FacebookTool)->execute([
            'action' => 'reply',
            'access_token' => 'test-facebook-token',
            'page_id' => 'page-1',
            'comment_id' => 'comment-1',
            'message' => 'Thanks for the feedback!',
        ]);

        $this->assertStringContainsString('reply-1', $result);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/comment-1/comments')
            && $request->hasHeader('Authorization', 'Bearer page-token-1'));
    }

    public function test_it_lists_comments_with_author_details(): void
    {
        Http::fake([
            'graph.facebook.com/*/post-1/comments*' => Http::response([
                'data' => [[
                    'id' => 'comment-9',
                    'message' => 'Nice post',
                    'from' => ['name' => 'Ada Lovelace'],
                    'like_count' => 2,
                    'comment_count' => 1,
                ]],
            ]),
        ]);

        $result = (new FacebookTool)->execute([
            'action' => 'list_comments',
            'access_token' => 'test-facebook-token',
            'object_id' => 'post-1',
        ]);

        $this->assertStringContainsString('Comment ID: comment-9', $result);
        $this->assertStringContainsString('Author: Ada Lovelace', $result);
        $this->assertStringContainsString('Likes: 2 | Replies: 1', $result);
    }

    public function test_it_hides_a_comment(): void
    {
        Http::fake([
            'graph.facebook.com/*/me/accounts*' => Http::response([
                'data' => [['id' => 'page-1', 'access_token' => 'page-token-1']],
            ]),
            'graph.facebook.com/*/comment-7*' => Http::response(['success' => true]),
        ]);

        $result = (new FacebookTool)->execute([
            'action' => 'hide_comment',
            'access_token' => 'test-facebook-token',
            'page_id' => 'page-1',
            'comment_id' => 'comment-7',
            'is_hidden' => true,
        ]);

        $this->assertSame('Comment hidden.', $result);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/comment-7')
            && ($request['is_hidden'] ?? null) === 'true'
            && $request->hasHeader('Authorization', 'Bearer page-token-1'));
    }

    public function test_it_requires_an_access_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A Facebook access token is required');

        (new FacebookTool)->execute([
            'action' => 'page',
            'page_id' => 'page-1',
        ]);
    }

    public function test_it_requires_a_page_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Facebook Page ID provided');

        (new FacebookTool)->execute([
            'action' => 'page',
            'access_token' => 'test-facebook-token',
        ]);
    }

    public function test_it_surfaces_graph_api_errors(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid OAuth access token.');

        (new FacebookTool)->execute([
            'action' => 'list_posts',
            'access_token' => 'test-facebook-token',
            'page_id' => 'page-1',
        ]);
    }

    public function test_it_rejects_unknown_actions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported facebook action');

        (new FacebookTool)->execute([
            'action' => 'explode',
            'access_token' => 'test-facebook-token',
        ]);
    }

    public function test_it_reads_credentials_from_the_credentials_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fb-creds-');
        file_put_contents($file, "FACEBOOK_ACCESS_TOKEN=token-from-file\nFACEBOOK_PAGE_ID=page-from-file\n");
        config()->set('agent.credentials_file', $file);

        Http::fake([
            'graph.facebook.com/*/page-from-file/posts*' => Http::response([
                'data' => [['id' => 'post-9', 'message' => 'Hi']],
            ]),
        ]);

        try {
            $result = (new FacebookTool)->execute(['action' => 'list_posts']);
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('Post ID: post-9', $result);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/page-from-file/posts')
            && $request->hasHeader('Authorization', 'Bearer token-from-file'));
    }

    public function test_the_tool_description_points_at_the_credentials_file(): void
    {
        $description = (new FacebookTool)->getDescription();

        $this->assertStringContainsString(config('agent.credentials_file'), $description);
        $this->assertStringContainsString('FACEBOOK_ACCESS_TOKEN', $description);
    }
}
