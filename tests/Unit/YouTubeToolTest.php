<?php

namespace Tests\Unit;

use App\Models\AgentSetting;
use App\Services\Agent\ToolRegistry;
use App\Services\Tools\YouTubeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class YouTubeToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_youtube_tool_is_registered(): void
    {
        $this->assertInstanceOf(YouTubeTool::class, app(ToolRegistry::class)->get('youtube'));
    }

    public function test_it_builds_the_oauth_consent_url(): void
    {
        config()->set('services.google.youtube_client_id', 'client-123');
        config()->set('services.google.youtube_redirect_uri', 'http://localhost:8100/oauth/youtube/callback');

        $result = (new YouTubeTool)->execute(['action' => 'auth_url']);

        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $result);
        $this->assertStringContainsString('client_id=client-123', $result);
        $this->assertStringContainsString('access_type=offline', $result);
        $this->assertStringContainsString('prompt=consent', $result);
        $this->assertStringContainsString('youtube.upload', $result);
        $this->assertStringContainsString(rawurlencode('http://localhost:8100/oauth/youtube/callback'), $result);
    }

    public function test_it_exchanges_an_authorization_code_for_a_refresh_token(): void
    {
        config()->set('services.google.youtube_client_id', 'client-123');
        config()->set('services.google.youtube_client_secret', 'secret-456');
        config()->set('services.google.youtube_redirect_uri', 'http://localhost:8100/oauth/youtube/callback');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'refresh-abc',
                'expires_in' => 3599,
            ]),
        ]);

        $result = (new YouTubeTool)->execute([
            'action' => 'exchange_code',
            'code' => 'auth-code-xyz',
        ]);

        $this->assertStringContainsString('refresh-abc', $result);
        $this->assertStringContainsString('fresh-access-token', $result);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code-xyz';
        });
    }

    public function test_it_mints_an_access_token_from_a_refresh_token(): void
    {
        config()->set('services.google.youtube_client_id', 'client-123');
        config()->set('services.google.youtube_client_secret', 'secret-456');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'minted-token']),
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [[
                    'id' => 'UC123',
                    'snippet' => ['title' => 'My Channel', 'customUrl' => '@mine'],
                    'statistics' => ['subscriberCount' => '42', 'videoCount' => '7', 'viewCount' => '900'],
                    'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU123']],
                ]],
            ]),
        ]);

        $result = (new YouTubeTool)->execute([
            'action' => 'channel',
            'refresh_token' => 'refresh-abc',
        ]);

        $this->assertStringContainsString('Channel: My Channel', $result);
        $this->assertStringContainsString('Uploads playlist: UU123', $result);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-abc');
    }

    public function test_it_lists_videos_from_the_uploads_playlist(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU123']]]],
            ]),
            'www.googleapis.com/youtube/v3/playlistItems*' => Http::response([
                'items' => [[
                    'snippet' => [
                        'title' => 'Vertical Short #1',
                        'publishedAt' => '2026-10-15T12:00:00Z',
                        'resourceId' => ['videoId' => 'vid-one'],
                    ],
                ]],
                'nextPageToken' => 'PAGE2',
            ]),
        ]);

        $result = (new YouTubeTool)->execute([
            'action' => 'list_videos',
            'access_token' => 'direct-token',
        ]);

        $this->assertStringContainsString('Vertical Short #1', $result);
        $this->assertStringContainsString('https://youtu.be/vid-one', $result);
        $this->assertStringContainsString('Next page token: PAGE2', $result);
    }

    public function test_it_uploads_a_local_video_file(): void
    {
        config()->set('services.google.youtube_client_id', 'client-123');
        config()->set('services.google.youtube_client_secret', 'secret-456');

        $path = tempnam(sys_get_temp_dir(), 'yt-test-').'.mp4';
        file_put_contents($path, str_repeat('a', 1024));

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'minted-token']),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://upload.session.example/session-1',
            ]),
            'upload.session.example/*' => Http::response([
                'id' => 'uploaded-vid',
                'snippet' => ['title' => 'My Short'],
                'status' => ['privacyStatus' => 'unlisted', 'uploadStatus' => 'uploaded'],
            ]),
        ]);

        try {
            $result = (new YouTubeTool)->execute([
                'action' => 'upload',
                'refresh_token' => 'refresh-abc',
                'video_path' => $path,
                'title' => 'My Short',
                'description' => 'A vertical short.',
                'tags' => ['shorts', 'demo'],
                'privacy_status' => 'unlisted',
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('uploaded-vid', $result);
        $this->assertStringContainsString('https://youtu.be/uploaded-vid', $result);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://www.googleapis.com/upload/youtube/v3/videos')
                && $request->method() === 'POST'
                && $request['snippet']['title'] === 'My Short'
                && $request['snippet']['tags'] === ['shorts', 'demo']
                && $request['status']['privacyStatus'] === 'unlisted';
        });

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://upload.session.example/session-1'
            && $request->method() === 'PUT');
    }

    public function test_it_uploads_publicly_by_default(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yt-test-').'.mp4';
        file_put_contents($path, str_repeat('a', 512));

        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://upload.session.example/session-2',
            ]),
            'upload.session.example/*' => Http::response([
                'id' => 'public-vid',
                'snippet' => ['title' => 'Public Short'],
                'status' => ['privacyStatus' => 'public', 'uploadStatus' => 'uploaded'],
            ]),
        ]);

        try {
            $result = (new YouTubeTool)->execute([
                'action' => 'upload',
                'access_token' => 'direct-token',
                'video_path' => $path,
                'title' => 'Public Short',
            ]);
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('public-vid', $result);
        $this->assertStringContainsString('Privacy: public', $result);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/upload/youtube/v3/videos')
            && $request['status']['privacyStatus'] === 'public');
    }

    public function test_it_marks_a_media_library_clip_as_posted_after_upload(): void
    {
        $mediaRoot = sys_get_temp_dir().'/yt-media-'.uniqid();
        mkdir($mediaRoot.'/YOUTUBE', 0775, true);
        config()->set('agent.media_root', $mediaRoot);

        $path = $mediaRoot.'/YOUTUBE/my-short.mp4';
        file_put_contents($path, str_repeat('a', 512));

        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://upload.session.example/session-3',
            ]),
            'upload.session.example/*' => Http::response([
                'id' => 'tracked-vid',
                'snippet' => ['title' => 'My Short'],
                'status' => ['privacyStatus' => 'public', 'uploadStatus' => 'uploaded'],
            ]),
        ]);

        try {
            (new YouTubeTool)->execute([
                'action' => 'upload',
                'access_token' => 'direct-token',
                'video_path' => $path,
                'title' => 'My Short',
            ]);
        } finally {
            @unlink($path);
            @rmdir($mediaRoot.'/YOUTUBE');
            @rmdir($mediaRoot);
        }

        $this->assertDatabaseHas('media_assets', [
            'slug' => 'my-short',
            'status' => 'posted',
            'platform' => 'youtube',
            'external_id' => 'tracked-vid',
            'external_url' => 'https://youtu.be/tracked-vid',
        ]);
    }

    public function test_it_requires_credentials_when_no_token_is_available(): void
    {
        config()->set('services.google.youtube_client_id', '');
        config()->set('services.google.youtube_client_secret', '');
        config()->set('services.google.youtube_refresh_token', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A YouTube access token is required');

        (new YouTubeTool)->execute(['action' => 'channel']);
    }

    public function test_it_is_disabled_when_the_setting_is_off(): void
    {
        AgentSetting::query()->create([
            'key' => 'enable_youtube',
            'value' => 'false',
            'type' => 'bool',
            'label' => 'Enable YouTube',
            'description' => '',
        ]);

        $this->assertFalse((new YouTubeTool)->isEnabled());
    }

    public function test_it_is_enabled_by_default(): void
    {
        $this->assertTrue((new YouTubeTool)->isEnabled());
    }

    public function test_it_reads_youtube_credentials_from_the_credentials_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'yt-creds-');
        file_put_contents($file, "YOUTUBE_CLIENT_ID=file-client\nYOUTUBE_CLIENT_SECRET=file-secret\nYOUTUBE_REFRESH_TOKEN=file-refresh\n");
        config()->set('agent.credentials_file', $file);
        config()->set('services.google.youtube_client_id', '');
        config()->set('services.google.youtube_client_secret', '');
        config()->set('services.google.youtube_refresh_token', '');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'minted-from-file']),
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [[
                    'snippet' => ['title' => 'YT From File'],
                    'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU-xyz']],
                ]],
            ]),
        ]);

        try {
            $result = (new YouTubeTool)->execute(['action' => 'channel']);
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('YT From File', $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'file-refresh');
    }

    public function test_the_youtube_tool_description_points_at_the_credentials_file(): void
    {
        $description = (new YouTubeTool)->getDescription();

        $this->assertStringContainsString(config('agent.credentials_file'), $description);
        $this->assertStringContainsString('YOUTUBE_REFRESH_TOKEN', $description);
    }
}
