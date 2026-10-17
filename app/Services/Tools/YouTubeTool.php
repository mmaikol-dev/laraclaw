<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeTool extends BaseTool
{
    private const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3';

    /**
     * @var array<int, string>
     */
    private const DEFAULT_SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    private string $accessToken = '';

    public function getName(): string
    {
        return 'youtube';
    }

    public function getDescription(): string
    {
        return 'Manage a YouTube channel through the YouTube Data API v3: read the authenticated channel, list and inspect videos, upload a local video file, update video metadata, delete a video, set a thumbnail, and list video categories. '
            .'Actions: auth_url, exchange_code, channel, list_videos, get_video, upload, update_video, delete_video, set_thumbnail, categories. '
            .'OAuth: the tool needs an access token. Either pass access_token directly, or pass/configure a refresh_token (YOUTUBE_REFRESH_TOKEN) plus client_id/client_secret (YOUTUBE_CLIENT_ID / YOUTUBE_CLIENT_SECRET) and it will mint an access token for you. '
            .'First-time setup: call auth_url, have the user open it and grant access, then call exchange_code with the returned code and store the refresh_token it returns. '
            .CredentialsFile::hint().' (keys: YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET, YOUTUBE_REFRESH_TOKEN); they are read from that file when not passed as arguments. Never print, log, or paste them. '
            .'upload takes a local file path in video_path (plus title, description, tags, category_id, privacy_status). Uploads default to privacy_status=public; pass private or unlisted to change visibility.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'auth_url', 'exchange_code', 'channel', 'list_videos', 'get_video',
                        'upload', 'update_video', 'delete_video', 'set_thumbnail', 'categories',
                    ],
                    'description' => implode(' ', [
                        'auth_url: build the Google OAuth consent URL for one-time authorization.',
                        'exchange_code: exchange an authorization code for a refresh token.',
                        'channel: read the authenticated channel (title, id, subscribers, uploads playlist).',
                        'list_videos: list the channel\'s uploaded videos.',
                        'get_video: read one video\'s snippet, status and statistics.',
                        'upload: upload a local video file to the channel.',
                        'update_video: change a video\'s title, description, tags, category or privacy.',
                        'delete_video: permanently delete a video.',
                        'set_thumbnail: set a custom thumbnail image for a video.',
                        'categories: list assignable video category ids and titles for a region.',
                    ]),
                ],
                'access_token' => ['type' => 'string', 'description' => 'Google OAuth access token. Optional; if omitted the tool mints one from the refresh token.'],
                'client_id' => ['type' => 'string', 'description' => 'Google OAuth client ID. Optional; defaults to YOUTUBE_CLIENT_ID in the credentials file ('.CredentialsFile::path().').'],
                'client_secret' => ['type' => 'string', 'description' => 'Google OAuth client secret. Optional; defaults to YOUTUBE_CLIENT_SECRET in the credentials file ('.CredentialsFile::path().').'],
                'refresh_token' => ['type' => 'string', 'description' => 'Google OAuth refresh token for the target account. Optional; defaults to YOUTUBE_REFRESH_TOKEN in the credentials file ('.CredentialsFile::path().').'],
                'redirect_uri' => ['type' => 'string', 'description' => 'OAuth redirect URI (auth_url, exchange_code). Defaults to YOUTUBE_REDIRECT_URI.'],
                'scope' => ['type' => 'string', 'description' => 'Space-separated OAuth scopes for auth_url. Defaults to upload + readonly + force-ssl.'],
                'code' => ['type' => 'string', 'description' => 'Authorization code returned to the redirect URI (exchange_code).'],
                'video_id' => ['type' => 'string', 'description' => 'YouTube video ID (get_video, update_video, delete_video, set_thumbnail).'],
                'video_path' => ['type' => 'string', 'description' => 'Absolute local path to the video file to upload (upload).'],
                'thumbnail_path' => ['type' => 'string', 'description' => 'Absolute local path to a JPEG/PNG thumbnail image (set_thumbnail, max 2MB).'],
                'title' => ['type' => 'string', 'description' => 'Video title (upload, update_video).'],
                'description' => ['type' => 'string', 'description' => 'Video description (upload, update_video).'],
                'tags' => ['type' => 'array', 'description' => 'List of keyword tags (upload, update_video).'],
                'category_id' => ['type' => 'string', 'description' => 'Numeric YouTube category ID, e.g. 22 People & Blogs, 28 Science & Technology (upload, update_video). Defaults to 22.'],
                'privacy_status' => ['type' => 'string', 'enum' => ['private', 'unlisted', 'public'], 'description' => 'Video visibility (upload, update_video). Uploads default to public.'],
                'made_for_kids' => ['type' => 'boolean', 'description' => 'Whether the video is made for kids (upload, update_video). Defaults to false.'],
                'publish_at' => ['type' => 'string', 'description' => 'Optional ISO 8601 scheduled publish time (upload, update_video). Requires privacy_status=private.'],
                'embed' => ['type' => 'boolean', 'description' => 'Allow embedding of the video (upload, update_video). Defaults to true.'],
                'notify_subscribers' => ['type' => 'boolean', 'description' => 'Notify subscribers about a new upload (upload). Defaults to false.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 25, max 50).'],
                'page_token' => ['type' => 'string', 'description' => 'Pagination token returned as "Next page token" by a previous list call.'],
                'region_code' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2 region for categories (default US).'],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_youtube', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $action = (string) ($arguments['action'] ?? '');

        if ($action === 'auth_url') {
            return $this->authUrl($arguments);
        }

        if ($action === 'exchange_code') {
            return $this->exchangeCode($arguments);
        }

        $this->accessToken = $this->resolveAccessToken($arguments);

        return match ($action) {
            'channel' => $this->channel(),
            'list_videos' => $this->listVideos($this->limit($arguments), $this->string($arguments, 'page_token')),
            'get_video' => $this->getVideo($this->id($arguments, 'video_id')),
            'upload' => $this->upload($arguments),
            'update_video' => $this->updateVideo($arguments),
            'delete_video' => $this->deleteVideo($this->id($arguments, 'video_id')),
            'set_thumbnail' => $this->setThumbnail(
                $this->id($arguments, 'video_id'),
                $this->required($arguments, 'thumbnail_path'),
            ),
            'categories' => $this->categories($this->string($arguments, 'region_code') ?: 'US'),
            default => throw new RuntimeException('Unsupported youtube action. Use one of: auth_url, exchange_code, channel, list_videos, get_video, upload, update_video, delete_video, set_thumbnail, categories.'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function authUrl(array $arguments): string
    {
        $clientId = $this->clientId($arguments);
        $redirectUri = $this->redirectUri($arguments);
        $scope = $this->string($arguments, 'scope');
        $scope = $scope !== '' ? $scope : implode(' ', self::DEFAULT_SCOPES);

        $url = self::OAUTH_AUTH_URL.'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);

        return "Open this URL, grant access, then copy the 'code' parameter from the redirect:\n{$url}";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function exchangeCode(array $arguments): string
    {
        $code = $this->required($arguments, 'code');

        $response = Http::asForm()->timeout(30)->post(self::OAUTH_TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId($arguments),
            'client_secret' => $this->clientSecret($arguments),
            'redirect_uri' => $this->redirectUri($arguments),
            'grant_type' => 'authorization_code',
        ]);

        $data = $this->decode($response, 'Unable to exchange the authorization code.');

        $refreshToken = (string) ($data['refresh_token'] ?? '');
        $lines = [
            'Authorization complete.',
            'Access token: '.($data['access_token'] ?? '(none)'),
            'Expires in: '.($data['expires_in'] ?? 'unknown').' seconds',
        ];

        if ($refreshToken !== '') {
            $lines[] = 'Refresh token (store this securely, e.g. YOUTUBE_REFRESH_TOKEN): '.$refreshToken;
        } else {
            $lines[] = 'No refresh token was returned. Revoke the app\'s access and call auth_url again with prompt=consent to force a new refresh token.';
        }

        return implode("\n", $lines);
    }

    private function channel(): string
    {
        $data = $this->apiGet('/channels', [
            'part' => 'snippet,statistics,contentDetails',
            'mine' => 'true',
        ]);

        $channel = $data['items'][0] ?? null;

        if (! is_array($channel)) {
            return 'No YouTube channel found for the authenticated account.';
        }

        $uploadPlaylist = $channel['contentDetails']['relatedPlaylists']['uploads'] ?? '(unknown)';

        return trim(sprintf(
            "Channel: %s\nID: %s\nHandle: %s\nSubscribers: %s\nVideos: %s\nViews: %s\nUploads playlist: %s",
            $channel['snippet']['title'] ?? '(unknown)',
            $channel['id'] ?? '(unknown)',
            $channel['snippet']['customUrl'] ?? '(none)',
            $channel['statistics']['subscriberCount'] ?? '0',
            $channel['statistics']['videoCount'] ?? '0',
            $channel['statistics']['viewCount'] ?? '0',
            $uploadPlaylist,
        ));
    }

    private function listVideos(int $limit, string $pageToken): string
    {
        $uploadsPlaylist = $this->uploadsPlaylistId();

        if ($uploadsPlaylist === '') {
            return 'No uploads playlist found for the authenticated channel.';
        }

        $query = [
            'part' => 'snippet,contentDetails',
            'playlistId' => $uploadsPlaylist,
            'maxResults' => $limit,
        ];

        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $data = $this->apiGet('/playlistItems', $query);
        $items = $data['items'] ?? [];

        if (! is_array($items) || $items === []) {
            return 'No videos found.';
        }

        $output = collect($items)->map(function (array $item): string {
            $snippet = $item['snippet'] ?? [];
            $videoId = $snippet['resourceId']['videoId'] ?? ($item['contentDetails']['videoId'] ?? '?');

            return trim(sprintf(
                "- [%s] %s\n  Published: %s\n  URL: https://youtu.be/%s",
                $videoId,
                $snippet['title'] ?? '(untitled)',
                $snippet['publishedAt'] ?? 'unknown',
                $videoId,
            ));
        })->implode("\n");

        $nextToken = $data['nextPageToken'] ?? null;

        if (is_string($nextToken) && $nextToken !== '') {
            $output .= "\n\nNext page token: {$nextToken}";
        }

        return $this->truncate($output, 300);
    }

    private function getVideo(string $videoId): string
    {
        $data = $this->apiGet('/videos', [
            'part' => 'snippet,status,statistics,contentDetails',
            'id' => $videoId,
        ]);

        $video = $data['items'][0] ?? null;

        if (! is_array($video)) {
            throw new RuntimeException("Video {$videoId} was not found.");
        }

        $snippet = $video['snippet'] ?? [];
        $status = $video['status'] ?? [];
        $stats = $video['statistics'] ?? [];

        return trim(sprintf(
            "Video: %s\nID: %s\nURL: https://youtu.be/%s\nChannel: %s\nPublished: %s\nPrivacy: %s\nUpload status: %s\nDuration: %s\nViews: %s | Likes: %s | Comments: %s\nTags: %s\nDescription:\n%s",
            $snippet['title'] ?? '(untitled)',
            $video['id'] ?? $videoId,
            $video['id'] ?? $videoId,
            $snippet['channelTitle'] ?? '(unknown)',
            $snippet['publishedAt'] ?? 'unknown',
            $status['privacyStatus'] ?? 'unknown',
            $status['uploadStatus'] ?? 'unknown',
            $video['contentDetails']['duration'] ?? 'unknown',
            $stats['viewCount'] ?? '0',
            $stats['likeCount'] ?? '0',
            $stats['commentCount'] ?? '0',
            implode(', ', $snippet['tags'] ?? []),
            $snippet['description'] ?? '',
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function upload(array $arguments): string
    {
        $path = $this->uploadPath($arguments, 'video_path');
        $title = $this->string($arguments, 'title');

        if ($title === '') {
            $title = pathinfo($path, PATHINFO_FILENAME);
        }

        $metadata = [
            'snippet' => array_filter([
                'title' => mb_substr($title, 0, 100),
                'description' => $this->string($arguments, 'description'),
                'tags' => $this->normalizeTags($arguments['tags'] ?? null),
                'categoryId' => $this->string($arguments, 'category_id') ?: '22',
            ], fn (mixed $value): bool => $value !== '' && $value !== []),
            'status' => [
                'privacyStatus' => $this->privacyStatus($arguments),
                'selfDeclaredMadeForKids' => (bool) ($arguments['made_for_kids'] ?? false),
                'embeddable' => (bool) ($arguments['embed'] ?? true),
            ],
        ];

        $publishAt = $this->string($arguments, 'publish_at');

        if ($publishAt !== '') {
            $metadata['status']['publishAt'] = $publishAt;
        }

        $notify = (bool) ($arguments['notify_subscribers'] ?? false);
        $size = filesize($path);
        $mime = $this->detectMime($path);

        $initUrl = self::UPLOAD_BASE.'/videos?'.http_build_query([
            'uploadType' => 'resumable',
            'part' => 'snippet,status',
            'notifySubscribers' => $notify ? 'true' : 'false',
        ]);

        $initResponse = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(60)
            ->withHeaders([
                'X-Upload-Content-Type' => $mime,
                'X-Upload-Content-Length' => (string) $size,
            ])
            ->post($initUrl, $metadata);

        if ($initResponse->failed()) {
            $this->decode($initResponse, 'Unable to start the resumable upload.');
        }

        $sessionUrl = (string) $initResponse->header('Location');

        if ($sessionUrl === '') {
            throw new RuntimeException('YouTube did not return an upload session URL.');
        }

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open the video file for upload: {$path}");
        }

        try {
            $uploadResponse = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout($this->uploadTimeout())
                ->withBody($stream, $mime)
                ->withHeaders(['Content-Length' => (string) $size])
                ->put($sessionUrl);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $data = $this->decode($uploadResponse, 'The video upload failed.');
        $videoId = (string) ($data['id'] ?? '');

        if ($videoId === '') {
            throw new RuntimeException('The upload completed but no video ID was returned.');
        }

        $library = app(MediaLibrary::class);
        $asset = $library->assetForFile($path);

        if ($asset !== null) {
            $library->markPosted($asset, 'youtube', $videoId, "https://youtu.be/{$videoId}");
        }

        return trim(sprintf(
            "Uploaded \"%s\" (%s, %s MB)\nVideo ID: %s\nURL: https://youtu.be/%s\nPrivacy: %s\nUpload status: %s",
            $data['snippet']['title'] ?? $title,
            $this->humanBytes($size),
            number_format($size / 1048576, 1),
            $videoId,
            $videoId,
            $data['status']['privacyStatus'] ?? $metadata['status']['privacyStatus'],
            $data['status']['uploadStatus'] ?? 'unknown',
        ));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function updateVideo(array $arguments): string
    {
        $videoId = $this->id($arguments, 'video_id');

        $current = $this->apiGet('/videos', [
            'part' => 'snippet,status',
            'id' => $videoId,
        ]);

        $video = $current['items'][0] ?? null;

        if (! is_array($video)) {
            throw new RuntimeException("Video {$videoId} was not found.");
        }

        $snippet = $video['snippet'] ?? [];
        $status = $video['status'] ?? [];

        foreach ([
            'title' => 'title',
            'description' => 'description',
            'category_id' => 'categoryId',
        ] as $argument => $field) {
            $value = $this->string($arguments, $argument);

            if ($value !== '') {
                $snippet[$field] = $value;
            }
        }

        if (array_key_exists('tags', $arguments)) {
            $snippet['tags'] = $this->normalizeTags($arguments['tags']);
        }

        if (array_key_exists('made_for_kids', $arguments)) {
            $status['selfDeclaredMadeForKids'] = (bool) $arguments['made_for_kids'];
        }

        if (array_key_exists('embed', $arguments)) {
            $status['embeddable'] = (bool) $arguments['embed'];
        }

        if (array_key_exists('privacy_status', $arguments) && $this->string($arguments, 'privacy_status') !== '') {
            $status['privacyStatus'] = $this->privacyStatus($arguments);
        }

        if (array_key_exists('publish_at', $arguments) && $this->string($arguments, 'publish_at') !== '') {
            $status['publishAt'] = $this->string($arguments, 'publish_at');
        }

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(60)
            ->put(self::API_BASE.'/videos?part=snippet,status', [
                'id' => $videoId,
                'snippet' => $snippet,
                'status' => $status,
            ]);

        $data = $this->decode($response, 'Unable to update the video.');

        return trim(sprintf(
            "Updated video %s\nTitle: %s\nPrivacy: %s",
            $videoId,
            $data['snippet']['title'] ?? ($snippet['title'] ?? '(unchanged)'),
            $data['status']['privacyStatus'] ?? ($status['privacyStatus'] ?? 'unknown'),
        ));
    }

    private function deleteVideo(string $videoId): string
    {
        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->delete(self::API_BASE.'/videos', ['id' => $videoId]);

        if ($response->failed()) {
            $this->decode($response, 'Unable to delete the video.');
        }

        return "Deleted video {$videoId}.";
    }

    private function setThumbnail(string $videoId, string $path): string
    {
        $path = $this->resolveReadablePath($path);

        if (filesize($path) > 2 * 1024 * 1024) {
            throw new RuntimeException('YouTube thumbnails must be 2MB or smaller.');
        }

        $mime = $this->detectMime($path);
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open the thumbnail file: {$path}");
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->acceptJson()
                ->timeout(60)
                ->withBody($stream, $mime)
                ->post(self::UPLOAD_BASE.'/thumbnails/set?'.http_build_query(['videoId' => $videoId]));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $data = $this->decode($response, 'Unable to set the video thumbnail.');

        return 'Thumbnail set for '.$videoId.'. Default: '.($data['items'][0]['default']['url'] ?? 'unknown');
    }

    private function categories(string $regionCode): string
    {
        $data = $this->apiGet('/videoCategories', [
            'part' => 'snippet',
            'regionCode' => $regionCode,
        ]);

        $items = $data['items'] ?? [];

        if (! is_array($items) || $items === []) {
            return "No assignable categories found for region {$regionCode}.";
        }

        $output = collect($items)
            ->map(fn (array $category): string => '- '.($category['id'] ?? '?').': '.($category['snippet']['title'] ?? '(untitled)'))
            ->implode("\n");

        return $this->truncate("Assignable categories for {$regionCode}:\n{$output}", 200);
    }

    private function uploadsPlaylistId(): string
    {
        $data = $this->apiGet('/channels', [
            'part' => 'contentDetails',
            'mine' => 'true',
        ]);

        return (string) ($data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveAccessToken(array $arguments): string
    {
        if (array_key_exists('access_token', $arguments) && $this->string($arguments, 'access_token') !== '') {
            return $this->string($arguments, 'access_token');
        }

        $refreshToken = $this->refreshToken($arguments);

        if ($refreshToken === '') {
            throw new RuntimeException('A YouTube access token is required. Pass access_token, or set YOUTUBE_REFRESH_TOKEN in '.CredentialsFile::path().' to mint one. For first-time setup, call action=auth_url then action=exchange_code.');
        }

        $response = Http::asForm()->timeout(30)->post(self::OAUTH_TOKEN_URL, [
            'client_id' => $this->clientId($arguments),
            'client_secret' => $this->clientSecret($arguments),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $data = $this->decode($response, 'Unable to refresh the YouTube access token.');

        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('The token refresh did not return an access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function clientId(array $arguments): string
    {
        $clientId = $this->string($arguments, 'client_id');
        $clientId = $clientId !== '' ? $clientId : (string) config('services.google.youtube_client_id', '');
        $clientId = $clientId !== '' ? $clientId : (string) CredentialsFile::get('YOUTUBE_CLIENT_ID', '');

        if ($clientId === '') {
            throw new RuntimeException('A Google OAuth client_id is required. Pass client_id or set YOUTUBE_CLIENT_ID in '.CredentialsFile::path().'.');
        }

        return $clientId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function clientSecret(array $arguments): string
    {
        $secret = $this->string($arguments, 'client_secret');
        $secret = $secret !== '' ? $secret : (string) config('services.google.youtube_client_secret', '');
        $secret = $secret !== '' ? $secret : (string) CredentialsFile::get('YOUTUBE_CLIENT_SECRET', '');

        if ($secret === '') {
            throw new RuntimeException('A Google OAuth client_secret is required. Pass client_secret or set YOUTUBE_CLIENT_SECRET in '.CredentialsFile::path().'.');
        }

        return $secret;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function refreshToken(array $arguments): string
    {
        $token = $this->string($arguments, 'refresh_token');
        $token = $token !== '' ? $token : (string) config('services.google.youtube_refresh_token', '');

        return $token !== '' ? $token : (string) CredentialsFile::get('YOUTUBE_REFRESH_TOKEN', '');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function redirectUri(array $arguments): string
    {
        $redirect = $this->string($arguments, 'redirect_uri');
        $redirect = $redirect !== '' ? $redirect : (string) config('services.google.youtube_redirect_uri', '');

        if ($redirect === '') {
            throw new RuntimeException('A redirect_uri is required for OAuth. Pass redirect_uri or set YOUTUBE_REDIRECT_URI.');
        }

        return $redirect;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function privacyStatus(array $arguments): string
    {
        $status = strtolower($this->string($arguments, 'privacy_status'));

        return in_array($status, ['private', 'unlisted', 'public'], true) ? $status : 'public';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function uploadPath(array $arguments, string $key): string
    {
        return $this->resolveReadablePath($this->required($arguments, $key));
    }

    private function resolveReadablePath(string $path): string
    {
        $path = trim($path);

        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("File is not readable: {$path}");
        }

        return $path;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function apiGet(string $path, array $query): array
    {
        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get(self::API_BASE.$path, $query);

        return $this->decode($response, "YouTube API request failed for {$path}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $message): array
    {
        $data = $response->json();

        if ($response->failed()) {
            $reason = is_array($data) ? ($data['error']['message'] ?? null) : null;

            throw new RuntimeException($message.' '.($reason ?? $response->body()));
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $tag): string => trim((string) $tag),
            $tags,
        ), fn (string $tag): bool => $tag !== ''));
    }

    private function detectMime(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    private function uploadTimeout(): int
    {
        $timeout = (int) config('services.google.youtube_upload_timeout', 1800);

        return max(60, $timeout);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$unit];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function required(array $arguments, string $key): string
    {
        $value = $this->string($arguments, $key);

        if ($value === '') {
            throw new RuntimeException("A {$key} is required for this action.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function id(array $arguments, string $key): string
    {
        return $this->required($arguments, $key);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function string(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function limit(array $arguments): int
    {
        return max(1, min(50, (int) ($arguments['limit'] ?? 25)));
    }
}
