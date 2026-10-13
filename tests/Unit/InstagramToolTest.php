<?php

namespace Tests\Unit;

use App\Services\Agent\ToolRegistry;
use App\Services\Tools\InstagramTool;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class InstagramToolTest extends TestCase
{
    public function test_the_instagram_tool_is_registered(): void
    {
        $this->assertInstanceOf(InstagramTool::class, app(ToolRegistry::class)->get('instagram'));
    }

    public function test_it_lists_account_media(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/media*' => Http::response([
                'data' => [[
                    'id' => 'media-1',
                    'caption' => 'Sunset over the bay',
                    'media_type' => 'IMAGE',
                    'media_product_type' => 'FEED',
                    'permalink' => 'https://instagram.com/p/media-1',
                    'timestamp' => '2026-01-01T00:00:00+0000',
                    'like_count' => 12,
                    'comments_count' => 4,
                ]],
                'paging' => ['cursors' => ['after' => 'IGCURSOR']],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'list_media',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
        ]);

        $this->assertStringContainsString('Media ID: media-1', $result);
        $this->assertStringContainsString('Type: IMAGE (FEED)', $result);
        $this->assertStringContainsString('Likes: 12', $result);
        $this->assertStringContainsString('Next cursor (after): IGCURSOR', $result);
    }

    public function test_it_lists_comments_with_replies(): void
    {
        Http::fake([
            'graph.facebook.com/*/media-1/comments*' => Http::response([
                'data' => [[
                    'id' => 'ig-comment-1',
                    'text' => 'Love this!',
                    'from' => ['username' => 'fan_account'],
                    'like_count' => 1,
                    'replies' => ['data' => [[
                        'id' => 'ig-reply-1',
                        'text' => 'Thank you!',
                        'from' => ['username' => 'brand'],
                    ]]],
                ]],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'list_comments',
            'access_token' => 'test-instagram-token',
            'media_id' => 'media-1',
        ]);

        $this->assertStringContainsString('Comment ID: ig-comment-1', $result);
        $this->assertStringContainsString('Author: fan_account', $result);
        $this->assertStringContainsString('ig-reply-1', $result);
        $this->assertStringContainsString('brand: Thank you!', $result);
    }

    public function test_it_replies_to_a_comment(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-comment-1/replies*' => Http::response(['id' => 'ig-new-reply']),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'reply',
            'access_token' => 'test-instagram-token',
            'comment_id' => 'ig-comment-1',
            'message' => 'So glad you liked it!',
        ]);

        $this->assertStringContainsString('ig-new-reply', $result);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/ig-comment-1/replies')
            && $request['message'] === 'So glad you liked it!');
    }

    public function test_it_comments_on_media(): void
    {
        Http::fake([
            'graph.facebook.com/*/media-1/comments*' => Http::response(['id' => 'ig-comment-new']),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'comment',
            'access_token' => 'test-instagram-token',
            'media_id' => 'media-1',
            'message' => 'Great shot!',
        ]);

        $this->assertStringContainsString('ig-comment-new', $result);
    }

    public function test_it_creates_a_container_and_publishes_it(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/media_publish*' => Http::response(['id' => 'published-1']),
            'graph.facebook.com/*/ig-account-1/media*' => Http::response(['id' => 'container-1']),
        ]);

        $tool = new InstagramTool;

        $created = $tool->execute([
            'action' => 'create_container',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
            'image_url' => 'https://example.com/image.jpg',
            'caption' => 'Hello Instagram',
            'media_type' => 'IMAGE',
        ]);

        $this->assertStringContainsString('container-1', $created);

        $published = $tool->execute([
            'action' => 'publish',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
            'creation_id' => 'container-1',
        ]);

        $this->assertStringContainsString('published-1', $published);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig-account-1/media_publish')
            && $request['creation_id'] === 'container-1');
    }

    public function test_it_reads_media_insights(): void
    {
        Http::fake([
            'graph.facebook.com/*/media-1/insights*' => Http::response([
                'data' => [[
                    'name' => 'impressions',
                    'title' => 'Impressions',
                    'period' => 'lifetime',
                    'values' => [['value' => 321]],
                ]],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'media_insights',
            'access_token' => 'test-instagram-token',
            'media_id' => 'media-1',
            'metric' => 'impressions',
        ]);

        $this->assertStringContainsString('Impressions (lifetime): 321', $result);
    }

    public function test_it_lists_dm_conversations(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/conversations*' => Http::response([
                'data' => [[
                    'id' => 'conv-1',
                    'messages' => ['data' => [[
                        'id' => 'msg-1',
                        'message' => 'Hi there',
                        'from' => ['id' => 'igsid-1', 'username' => 'fan'],
                        'created_time' => '2026-01-01T00:00:00+0000',
                    ]]],
                ]],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'list_conversations',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
        ]);

        $this->assertStringContainsString('Conversation: conv-1', $result);
        $this->assertStringContainsString('Latest from fan: Hi there', $result);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig-account-1/conversations')
            && $request['platform'] === 'instagram');
    }

    public function test_it_reads_a_dm_conversation_by_id(): void
    {
        Http::fake([
            'graph.facebook.com/*/conv-9*' => Http::response([
                'id' => 'conv-9',
                'messages' => ['data' => [
                    ['id' => 'msg-2', 'message' => 'How much?', 'from' => ['username' => 'fan'], 'created_time' => '2026-01-01T00:00:00+0000'],
                    ['id' => 'msg-3', 'message' => '$50', 'from' => ['username' => 'brand'], 'created_time' => '2026-01-01T00:00:05+0000'],
                ]],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'get_conversation',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
            'conversation_id' => 'conv-9',
        ]);

        $this->assertStringContainsString('Conversation: conv-9', $result);
        $this->assertStringContainsString('[msg-2] fan (2026-01-01T00:00:00+0000)', $result);
        $this->assertStringContainsString('How much?', $result);
        $this->assertStringContainsString('$50', $result);
    }

    public function test_it_reads_a_single_dm_message(): void
    {
        Http::fake([
            'graph.facebook.com/*/msg-9*' => Http::response([
                'id' => 'msg-9',
                'message' => 'Hello!',
                'from' => ['username' => 'fan'],
                'to' => ['data' => [['username' => 'brand']]],
                'created_time' => '2026-01-01T00:00:00+0000',
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'get_message',
            'access_token' => 'test-instagram-token',
            'message_id' => 'msg-9',
        ]);

        $this->assertStringContainsString('[msg-9] fan', $result);
        $this->assertStringContainsString('Hello!', $result);
    }

    public function test_it_sends_a_dm(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/messages*' => Http::response(['message_id' => 'mid-1']),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'send_message',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
            'recipient_id' => 'igsid-1',
            'message' => 'Hi, thanks for reaching out!',
        ]);

        $this->assertStringContainsString('Message sent. ID: mid-1', $result);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig-account-1/messages')
            && json_decode($request->body(), true)['recipient']['id'] === 'igsid-1'
            && json_decode($request->body(), true)['message']['text'] === 'Hi, thanks for reaching out!');
    }

    public function test_it_marks_a_dm_thread_as_seen(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/messages*' => Http::response(['ok' => true]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'mark_seen',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
            'recipient_id' => 'igsid-1',
        ]);

        $this->assertSame('Conversation marked as seen.', $result);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/ig-account-1/messages')
            && json_decode($request->body(), true)['sender_action'] === 'mark_seen');
    }

    public function test_it_lists_stories(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/stories*' => Http::response([
                'data' => [[
                    'id' => 'story-1',
                    'media_type' => 'VIDEO',
                    'media_url' => 'https://example.com/story.mp4',
                    'timestamp' => '2026-01-01T12:00:00+0000',
                    'permalink' => 'https://instagram.com/stories/story-1',
                ]],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'list_stories',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
        ]);

        $this->assertStringContainsString('Media ID: story-1', $result);
        $this->assertStringContainsString('Type: VIDEO', $result);
    }

    public function test_it_lists_authorized_ad_accounts(): void
    {
        Http::fake([
            'graph.facebook.com/*/ig-account-1/authorized_adaccounts*' => Http::response([
                'data' => [[
                    'id' => 'aa-1',
                    'name' => 'Colony Ads',
                    'account_id' => 'act_123',
                    'account_status' => '1',
                    'currency' => 'USD',
                    'timezone_name' => 'America/Los_Angeles',
                ]],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'list_ad_accounts',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
        ]);

        $this->assertStringContainsString('Ad account: Colony Ads', $result);
        $this->assertStringContainsString('Account ID: act_123', $result);
    }

    public function test_it_runs_business_discovery(): void
    {
        Http::fake([
            '*business_discovery*' => Http::response([
                'business_discovery' => [
                    'username' => 'brandx',
                    'name' => 'Brand X',
                    'biography' => 'Lifestyle brand',
                    'followers_count' => 1234,
                    'media_count' => 99,
                    'media' => ['data' => [[
                        'id' => 'p1',
                        'caption' => 'Hello world',
                        'permalink' => 'https://instagram.com/p/p1',
                    ]]],
                ],
            ]),
        ]);

        $result = (new InstagramTool)->execute([
            'action' => 'business_discovery',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
            'username' => 'brandx',
        ]);

        $this->assertStringContainsString('Brand X (@brandx)', $result);
        $this->assertStringContainsString('Followers: 1234', $result);
        $this->assertStringContainsString('Hello world', $result);
    }

    public function test_it_requires_an_access_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('An Instagram access token is required');

        (new InstagramTool)->execute([
            'action' => 'account',
            'account_id' => 'ig-account-1',
        ]);
    }

    public function test_it_requires_an_account_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Instagram account ID provided');

        (new InstagramTool)->execute([
            'action' => 'account',
            'access_token' => 'test-instagram-token',
        ]);
    }

    public function test_it_surfaces_graph_api_errors(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session has expired.']], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Session has expired.');

        (new InstagramTool)->execute([
            'action' => 'list_media',
            'access_token' => 'test-instagram-token',
            'account_id' => 'ig-account-1',
        ]);
    }

    public function test_it_rejects_unknown_actions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported instagram action');

        (new InstagramTool)->execute([
            'action' => 'explode',
            'access_token' => 'test-instagram-token',
        ]);
    }
}
