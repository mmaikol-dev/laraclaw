<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramTool extends BaseTool
{
    private string $accessToken = '';

    public function getName(): string
    {
        return 'instagram';
    }

    public function getDescription(): string
    {
        return 'Manage an Instagram Business/Creator account through the Instagram Graph API. Content: read the account, its media and stories; read comments; comment on media; reply to, hide or delete comments; publish media (image, video, reel, or story) via containers. Messaging (DMs): list conversations, read a conversation or a single message, send messages, and mark a thread as seen. Ads: list the ad accounts authorized to advertise for the account. Creators: research any Instagram business/creator account by username. Actions: account, list_media, get_media, list_comments, comment, reply, delete_comment, hide_comment, create_container, publish, media_insights, account_insights, list_conversations, get_conversation, get_message, send_message, mark_seen, list_stories, list_ad_accounts, business_discovery. '
            .CredentialsFile::hint().' (keys: INSTAGRAM_ACCESS_TOKEN, INSTAGRAM_ACCOUNT_ID). Pass access_token and account_id with the call, or omit them and the tool reads them from that file. Never print, log, or paste the token.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'account', 'list_media', 'get_media', 'list_comments',
                        'comment', 'reply', 'delete_comment', 'hide_comment',
                        'create_container', 'publish', 'media_insights', 'account_insights',
                        'list_conversations', 'get_conversation', 'get_message',
                        'send_message', 'mark_seen', 'list_stories',
                        'list_ad_accounts', 'business_discovery',
                    ],
                    'description' => 'The Instagram operation to perform.',
                ],
                'access_token' => ['type' => 'string', 'description' => 'Instagram Graph access token. Optional: defaults to INSTAGRAM_ACCESS_TOKEN in the credentials file ('.CredentialsFile::path().').'],
                'account_id' => ['type' => 'string', 'description' => 'Instagram Business account ID. Optional: defaults to INSTAGRAM_ACCOUNT_ID in the credentials file ('.CredentialsFile::path().').'],
                'media_id' => ['type' => 'string', 'description' => 'Instagram media (post) ID.'],
                'comment_id' => ['type' => 'string', 'description' => 'Instagram comment ID (reply, delete_comment, hide_comment).'],
                'message' => ['type' => 'string', 'description' => 'Text used by comment, reply, and send_message.'],
                'caption' => ['type' => 'string', 'description' => 'Caption for a media container.'],
                'image_url' => ['type' => 'string', 'description' => 'Publicly reachable image URL for create_container.'],
                'video_url' => ['type' => 'string', 'description' => 'Publicly reachable video URL for create_container.'],
                'media_type' => ['type' => 'string', 'description' => 'Container type: IMAGE, VIDEO, REELS, or STORIES (default IMAGE).'],
                'creation_id' => ['type' => 'string', 'description' => 'Container creation ID returned by create_container, used by publish.'],
                'asset' => ['type' => 'string', 'description' => 'Optional media-library clip (slug or filename) that this post publishes. When set, a successful publish marks that clip as posted so it is not posted twice. Check media_clip action=library for unposted clips.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 25, max 100).'],
                'after' => ['type' => 'string', 'description' => 'Pagination cursor returned as "Next cursor" by a previous list call.'],
                'hide' => ['type' => 'boolean', 'description' => 'hide_comment: true to hide the comment, false to unhide it.'],
                'metric' => ['type' => 'string', 'description' => 'Comma-separated insights metrics, e.g. impressions,reach,likes or engagement,impressions.'],
                'period' => ['type' => 'string', 'description' => 'Account insights period: day, week, days_28, month, or lifetime.'],
                'conversation_id' => ['type' => 'string', 'description' => 'DM conversation ID returned by list_conversations (get_conversation).'],
                'user_id' => ['type' => 'string', 'description' => 'Instagram-scoped ID (IGSID) of a participant. get_conversation can use this instead of conversation_id.'],
                'message_id' => ['type' => 'string', 'description' => 'DM message ID (get_message).'],
                'recipient_id' => ['type' => 'string', 'description' => 'Instagram-scoped ID (IGSID) of the person to message (send_message, mark_seen). Read it from a conversation or message response.'],
                'attachment_url' => ['type' => 'string', 'description' => 'Public image URL to attach when sending a DM (send_message). Text message is used when absent.'],
                'reply_to' => ['type' => 'string', 'description' => 'Message ID this DM replies to (send_message).'],
                'username' => ['type' => 'string', 'description' => 'Instagram username (with or without @) of an account to research via business_discovery.'],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_instagram', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $this->accessToken = $this->resolveAccessToken($arguments);

        return match ($arguments['action'] ?? null) {
            'account' => $this->account($this->accountId($arguments)),
            'list_media' => $this->listMedia(
                $this->accountId($arguments),
                $this->limit($arguments),
                $this->string($arguments, 'after'),
            ),
            'get_media' => $this->getMedia($this->id($arguments, 'media_id')),
            'list_comments' => $this->listComments(
                $this->id($arguments, 'media_id'),
                $this->limit($arguments),
                $this->string($arguments, 'after'),
            ),
            'comment' => $this->comment(
                $this->id($arguments, 'media_id'),
                $this->requiredMessage($arguments),
            ),
            'reply' => $this->reply(
                $this->id($arguments, 'comment_id'),
                $this->requiredMessage($arguments),
            ),
            'delete_comment' => $this->deleteComment($this->id($arguments, 'comment_id')),
            'hide_comment' => $this->hideComment(
                $this->id($arguments, 'comment_id'),
                (bool) ($arguments['hide'] ?? true),
            ),
            'create_container' => $this->createContainer($this->accountId($arguments), $arguments),
            'publish' => $this->publish(
                $this->accountId($arguments),
                $this->id($arguments, 'creation_id'),
                $this->string($arguments, 'asset'),
            ),
            'media_insights' => $this->mediaInsights(
                $this->id($arguments, 'media_id'),
                $this->requiredMetric($arguments),
            ),
            'account_insights' => $this->accountInsights(
                $this->accountId($arguments),
                $this->requiredMetric($arguments),
                $this->string($arguments, 'period') ?: 'day',
            ),
            'list_conversations' => $this->listConversations(
                $this->accountId($arguments),
                $this->limit($arguments),
                $this->string($arguments, 'after'),
            ),
            'get_conversation' => $this->getConversation($this->accountId($arguments), $arguments),
            'get_message' => $this->getMessage($this->id($arguments, 'message_id')),
            'send_message' => $this->sendMessage($this->accountId($arguments), $arguments),
            'mark_seen' => $this->markSeen($this->accountId($arguments), $this->id($arguments, 'recipient_id')),
            'list_stories' => $this->listStories(
                $this->accountId($arguments),
                $this->limit($arguments),
                $this->string($arguments, 'after'),
            ),
            'list_ad_accounts' => $this->listAdAccounts($this->accountId($arguments)),
            'business_discovery' => $this->businessDiscovery($this->accountId($arguments), $this->string($arguments, 'username')),
            default => throw new RuntimeException('Unsupported instagram action. Use one of: account, list_media, get_media, list_comments, comment, reply, delete_comment, hide_comment, create_container, publish, media_insights, account_insights, list_conversations, get_conversation, get_message, send_message, mark_seen, list_stories, list_ad_accounts, business_discovery.'),
        };
    }

    private function account(string $accountId): string
    {
        $data = $this->request('GET', "/{$accountId}", [
            'fields' => 'id,username,name,biography,website,followers_count,follows_count,media_count,profile_picture_url',
        ]);

        $lines = ['Account: @'.($data['username'] ?? '(unknown)'), 'ID: '.($data['id'] ?? '(unknown)')];

        foreach (['name' => 'Name', 'biography' => 'Bio', 'website' => 'Website'] as $key => $label) {
            if (! empty($data[$key])) {
                $lines[] = "{$label}: {$data[$key]}";
            }
        }

        if (isset($data['followers_count'])) {
            $lines[] = 'Followers: '.$data['followers_count'];
        }

        if (isset($data['follows_count'])) {
            $lines[] = 'Following: '.$data['follows_count'];
        }

        if (isset($data['media_count'])) {
            $lines[] = 'Posts: '.$data['media_count'];
        }

        return implode("\n", $lines);
    }

    private function listMedia(string $accountId, int $limit, string $after): string
    {
        $data = $this->request('GET', "/{$accountId}/media", $this->withCursor([
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
            'limit' => $limit,
        ], $after));

        return $this->formatCollection($data, 'media', fn (array $media): string => $this->formatMedia($media));
    }

    private function getMedia(string $mediaId): string
    {
        $data = $this->request('GET', "/{$mediaId}", [
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count',
        ]);

        return $this->formatMedia($data);
    }

    private function listComments(string $mediaId, int $limit, string $after): string
    {
        $data = $this->request('GET', "/{$mediaId}/comments", $this->withCursor([
            'fields' => 'id,text,from,timestamp,like_count,replies{id,text,from,timestamp}',
            'limit' => $limit,
        ], $after));

        return $this->formatCollection($data, 'comments', fn (array $comment): string => $this->formatComment($comment));
    }

    private function comment(string $mediaId, string $message): string
    {
        $data = $this->request('POST', "/{$mediaId}/comments", ['message' => $message]);

        return 'Posted comment. ID: '.($data['id'] ?? '(unknown)');
    }

    private function reply(string $commentId, string $message): string
    {
        $data = $this->request('POST', "/{$commentId}/replies", ['message' => $message]);

        return 'Posted reply. ID: '.($data['id'] ?? '(unknown)');
    }

    private function hideComment(string $commentId, bool $hide): string
    {
        $this->request('POST', "/{$commentId}", ['hide' => $hide ? 'true' : 'false']);

        return $hide ? 'Comment hidden.' : 'Comment unhidden.';
    }

    private function deleteComment(string $commentId): string
    {
        $this->request('DELETE', "/{$commentId}");

        return "Comment {$commentId} deleted.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function createContainer(string $accountId, array $arguments): string
    {
        $imageUrl = $this->string($arguments, 'image_url');
        $videoUrl = $this->string($arguments, 'video_url');

        if ($imageUrl === '' && $videoUrl === '') {
            throw new RuntimeException('create_container requires image_url or video_url.');
        }

        $payload = [];

        if ($imageUrl !== '') {
            $payload['image_url'] = $imageUrl;
        }

        if ($videoUrl !== '') {
            $payload['video_url'] = $videoUrl;
        }

        $caption = $this->string($arguments, 'caption');

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        $mediaType = strtoupper($this->string($arguments, 'media_type'));

        if ($mediaType !== '') {
            $payload['media_type'] = $mediaType;
        }

        $data = $this->request('POST', "/{$accountId}/media", $payload);

        return 'Created media container. ID: '.($data['id'] ?? '(unknown)')."\nUse the publish action with this creation_id to publish it.";
    }

    private function publish(string $accountId, string $creationId, string $asset = ''): string
    {
        $data = $this->request('POST', "/{$accountId}/media_publish", ['creation_id' => $creationId]);

        $mediaId = (string) ($data['id'] ?? '');

        if ($asset !== '' && $mediaId !== '') {
            $library = app(MediaLibrary::class);
            $tracked = $library->resolve($asset);

            if ($tracked !== null) {
                $library->markPosted($tracked, 'instagram', $mediaId, null);
            }
        }

        return 'Published media. ID: '.($mediaId !== '' ? $mediaId : '(unknown)');
    }

    private function mediaInsights(string $mediaId, string $metric): string
    {
        return $this->formatInsights($this->request('GET', "/{$mediaId}/insights", ['metric' => $metric]));
    }

    private function accountInsights(string $accountId, string $metric, string $period): string
    {
        return $this->formatInsights($this->request('GET', "/{$accountId}/insights", [
            'metric' => $metric,
            'period' => $period,
        ]));
    }

    private function listConversations(string $accountId, int $limit, string $after): string
    {
        $data = $this->request('GET', "/{$accountId}/conversations", $this->withCursor([
            'platform' => 'instagram',
            'fields' => 'id,messages.limit(1){id,message,from,to,created_time}',
            'limit' => $limit,
        ], $after));

        return $this->formatCollection($data, 'conversations', fn (array $conversation): string => $this->formatConversationPreview($conversation));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function getConversation(string $accountId, array $arguments): string
    {
        $conversationId = $this->string($arguments, 'conversation_id');
        $userId = $this->string($arguments, 'user_id');

        if ($conversationId === '' && $userId === '') {
            throw new RuntimeException('get_conversation requires conversation_id or user_id (the Instagram-scoped ID of the participant).');
        }

        $fields = 'id,messages.limit('.$this->limit($arguments).'){id,message,from,to,created_time}';

        if ($conversationId !== '') {
            return $this->formatConversation($this->request('GET', "/{$conversationId}", ['fields' => $fields]));
        }

        return $this->formatConversation($this->request('GET', "/{$accountId}/conversations", [
            'platform' => 'instagram',
            'user_id' => $userId,
            'fields' => $fields,
        ]));
    }

    private function getMessage(string $messageId): string
    {
        $data = $this->request('GET', "/{$messageId}", [
            'fields' => 'id,created_time,from,to,message,attachments',
        ]);

        return $this->formatMessage($data);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function sendMessage(string $accountId, array $arguments): string
    {
        $recipientId = $this->id($arguments, 'recipient_id');
        $text = $this->string($arguments, 'message');
        $attachmentUrl = $this->string($arguments, 'attachment_url');

        if ($text === '' && $attachmentUrl === '') {
            throw new RuntimeException('send_message requires message text or attachment_url.');
        }

        $message = $text !== '' ? ['text' => $text] : [];

        if ($attachmentUrl !== '') {
            $message['attachment'] = ['type' => 'image', 'payload' => ['url' => $attachmentUrl]];
        }

        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => $message,
        ];

        $replyTo = $this->string($arguments, 'reply_to');

        if ($replyTo !== '') {
            $payload['reply_to'] = ['mid' => $replyTo];
        }

        $data = $this->request('POST', "/{$accountId}/messages", $payload, true);

        return 'Message sent. ID: '.($data['message_id'] ?? '(unknown)');
    }

    private function markSeen(string $accountId, string $recipientId): string
    {
        $this->request('POST', "/{$accountId}/messages", [
            'recipient' => ['id' => $recipientId],
            'sender_action' => 'mark_seen',
        ], true);

        return 'Conversation marked as seen.';
    }

    private function listStories(string $accountId, int $limit, string $after): string
    {
        $data = $this->request('GET', "/{$accountId}/stories", $this->withCursor([
            'fields' => 'id,media_type,media_url,thumbnail_url,caption,timestamp,permalink,like_count',
            'limit' => $limit,
        ], $after));

        return $this->formatCollection($data, 'stories', fn (array $story): string => $this->formatMedia($story));
    }

    private function listAdAccounts(string $accountId): string
    {
        $data = $this->request('GET', "/{$accountId}/authorized_adaccounts", [
            'fields' => 'id,name,account_id,account_status,currency,timezone_name',
        ]);

        return $this->formatCollection($data, 'ad accounts', fn (array $account): string => $this->formatAdAccount($account));
    }

    private function businessDiscovery(string $accountId, string $username): string
    {
        $username = ltrim($username, '@');

        if ($username === '') {
            throw new RuntimeException('business_discovery requires a username.');
        }

        $data = $this->request('GET', "/{$accountId}", [
            'fields' => "business_discovery.username({$username}){id,username,name,biography,website,followers_count,media_count,media.limit(10){id,caption,timestamp,media_type,permalink,like_count,comments_count}}",
        ]);

        return $this->formatDiscovery($data['business_discovery'] ?? $data);
    }

    /**
     * @param  array<string, mixed>  $conversation
     */
    private function formatConversationPreview(array $conversation): string
    {
        $lines = ['Conversation: '.($conversation['id'] ?? '(unknown)')];

        $messages = $conversation['messages']['data'] ?? [];

        if (is_array($messages) && $messages !== []) {
            $latest = $messages[0];
            $from = $latest['from']['username'] ?? ($latest['from']['id'] ?? 'unknown');
            $lines[] = 'Latest from '.$from.': '.$this->snippet((string) ($latest['message'] ?? '[attachment]'));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $conversation
     */
    private function formatConversation(array $conversation): string
    {
        $lines = ['Conversation: '.($conversation['id'] ?? '(unknown)')];

        $messages = $conversation['messages']['data'] ?? [];

        if (! is_array($messages) || $messages === []) {
            $lines[] = '(no messages)';

            return implode("\n", $lines);
        }

        foreach ($messages as $message) {
            $lines[] = $this->formatMessage($message);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function formatMessage(array $message): string
    {
        $from = $message['from']['username'] ?? ($message['from']['id'] ?? 'unknown');
        $lines = ['['.($message['id'] ?? '?').'] '.$from.' ('.($message['created_time'] ?? '').')'];

        $text = trim((string) ($message['message'] ?? ''));

        if ($text !== '') {
            $lines[] = '  '.$this->snippet($text);
        }

        $attachments = $message['attachments']['data'] ?? [];

        if (is_array($attachments) && $attachments !== []) {
            $lines[] = '  [attachment: '.($attachments[0]['type'] ?? 'media').']';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function formatAdAccount(array $account): string
    {
        $lines = ['Ad account: '.($account['name'] ?? $account['account_id'] ?? '(unknown)')];

        foreach (['id' => 'ID', 'account_id' => 'Account ID', 'account_status' => 'Status', 'currency' => 'Currency', 'timezone_name' => 'Timezone'] as $key => $label) {
            if (isset($account[$key])) {
                $lines[] = $label.': '.$account[$key];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function formatDiscovery(array $profile): string
    {
        $lines = [($profile['name'] ?? $profile['username'] ?? 'Account').' (@'.($profile['username'] ?? '?').')', 'ID: '.($profile['id'] ?? '(unknown)')];

        if (! empty($profile['biography'])) {
            $lines[] = 'Bio: '.$this->snippet((string) $profile['biography']);
        }

        if (! empty($profile['website'])) {
            $lines[] = 'Website: '.$profile['website'];
        }

        foreach ([['followers_count', 'Followers'], ['media_count', 'Posts']] as [$key, $label]) {
            if (isset($profile[$key])) {
                $lines[] = $label.': '.$profile[$key];
            }
        }

        $media = $profile['media']['data'] ?? [];

        if (is_array($media) && $media !== []) {
            $lines[] = 'Recent posts:';

            foreach ($media as $post) {
                $lines[] = '  - ['.$post['id'].'] '.$this->snippet((string) ($post['caption'] ?? '')).($post['permalink'] ?? '');
            }
        }

        return $this->truncate(implode("\n", $lines), 300);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function accountId(array $arguments): string
    {
        $accountId = trim((string) ($arguments['account_id'] ?? ''));

        if ($accountId === '') {
            $accountId = (string) CredentialsFile::get('INSTAGRAM_ACCOUNT_ID', '');
        }

        if ($accountId === '') {
            throw new RuntimeException('No Instagram account ID provided. Pass account_id or set INSTAGRAM_ACCOUNT_ID in '.CredentialsFile::path().'.');
        }

        return $accountId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredMetric(array $arguments): string
    {
        $metric = $this->string($arguments, 'metric');

        if ($metric === '') {
            throw new RuntimeException('A metric is required for insights, e.g. impressions,reach.');
        }

        return $metric;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredMessage(array $arguments): string
    {
        $message = trim((string) ($arguments['message'] ?? ''));

        if ($message === '') {
            throw new RuntimeException('A message is required for this action.');
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function id(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        if ($value === '') {
            throw new RuntimeException("A {$key} is required for this action.");
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
     * @param  array<string, mixed>  $arguments
     */
    private function limit(array $arguments): int
    {
        return max(1, min(100, (int) ($arguments['limit'] ?? 25)));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withCursor(array $params, string $after): array
    {
        if ($after !== '') {
            $params['after'] = $after;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params = [], bool $jsonBody = false): array
    {
        $url = rtrim((string) config('services.instagram.graph_url', 'https://graph.facebook.com/v21.0'), '/').'/'.ltrim($path, '/');
        $http = Http::withToken($this->accessToken)->acceptJson()->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $params),
            'POST' => $jsonBody ? $http->post($url, $params) : $http->asForm()->post($url, $params),
            'DELETE' => $http->delete($url, $params),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}."),
        };

        $data = $response->json();

        if ($response->failed()) {
            $message = is_array($data) && isset($data['error']['message'])
                ? (string) $data['error']['message']
                : $response->body();

            throw new RuntimeException("Instagram Graph API request failed: {$message}");
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveAccessToken(array $arguments): string
    {
        $token = trim((string) ($arguments['access_token'] ?? ''));

        if ($token === '') {
            $token = (string) CredentialsFile::get('INSTAGRAM_ACCESS_TOKEN', '');
        }

        if ($token === '') {
            throw new RuntimeException('An Instagram access token is required. Pass access_token or set INSTAGRAM_ACCESS_TOKEN in '.CredentialsFile::path().'.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatCollection(array $data, string $label, callable $formatter): string
    {
        $items = $data['data'] ?? [];

        if (! is_array($items) || $items === []) {
            return "No {$label} found.";
        }

        $output = collect($items)->map(fn (array $item): string => $formatter($item))->implode("\n\n---\n\n");

        $cursor = $data['paging']['cursors']['after'] ?? null;

        if (is_string($cursor) && $cursor !== '') {
            $output .= "\n\nNext cursor (after): {$cursor}";
        }

        return $this->truncate($output, 300);
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function formatMedia(array $media): string
    {
        $lines = ['Media ID: '.($media['id'] ?? '(unknown)')];

        if (! empty($media['media_type'])) {
            $lines[] = 'Type: '.$media['media_type'].(! empty($media['media_product_type']) ? ' ('.$media['media_product_type'].')' : '');
        }

        if (! empty($media['timestamp'])) {
            $lines[] = 'Posted: '.$media['timestamp'];
        }

        if (! empty($media['caption'])) {
            $lines[] = 'Caption: '.$this->snippet((string) $media['caption']);
        }

        if (! empty($media['permalink'])) {
            $lines[] = 'Permalink: '.$media['permalink'];
        }

        if (! empty($media['media_url'])) {
            $lines[] = 'Media URL: '.$media['media_url'];
        }

        if (isset($media['like_count'])) {
            $lines[] = 'Likes: '.$media['like_count'];
        }

        if (isset($media['comments_count'])) {
            $lines[] = 'Comments: '.$media['comments_count'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    private function formatComment(array $comment): string
    {
        $lines = [
            'Comment ID: '.($comment['id'] ?? '(unknown)'),
            'Author: '.($comment['from']['username'] ?? $comment['username'] ?? 'Unknown'),
        ];

        if (! empty($comment['timestamp'])) {
            $lines[] = 'Posted: '.$comment['timestamp'];
        }

        $lines[] = 'Text: '.$this->snippet((string) ($comment['text'] ?? ''));

        if (isset($comment['like_count'])) {
            $lines[] = 'Likes: '.$comment['like_count'];
        }

        $replies = $comment['replies']['data'] ?? [];

        if (is_array($replies) && $replies !== []) {
            $lines[] = 'Replies:';

            foreach ($replies as $reply) {
                $lines[] = '  - ['.($reply['id'] ?? '?').'] '.($reply['from']['username'] ?? 'Unknown').': '.$this->snippet((string) ($reply['text'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatInsights(array $data): string
    {
        $rows = $data['data'] ?? [];

        if (! is_array($rows) || $rows === []) {
            return 'No insights returned.';
        }

        $output = collect($rows)->map(function (array $metric): string {
            $values = collect($metric['values'] ?? [])->map(function ($entry): string {
                $value = is_array($entry) ? ($entry['value'] ?? '') : $entry;
                $end = is_array($entry) ? ($entry['end_time'] ?? '') : '';

                return trim(($end !== '' ? $end.': ' : '').(is_array($value) ? json_encode($value) : (string) $value));
            })->implode(', ');

            return ($metric['title'] ?? $metric['name'] ?? 'metric').(! empty($metric['period']) ? " ({$metric['period']})" : '').": {$values}";
        })->implode("\n");

        return $this->truncate($output, 100);
    }

    private function snippet(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > 400 ? mb_substr($value, 0, 400).'…' : $value;
    }
}
