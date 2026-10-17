<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FacebookTool extends BaseTool
{
    private string $accessToken = '';

    /** @var array<string, string> */
    private array $pageTokens = [];

    public function getName(): string
    {
        return 'facebook';
    }

    public function getDescription(): string
    {
        return 'Manage a Facebook Page through the Meta Graph API: read the page and its posts, publish and delete posts, read comments, comment on posts, reply to comments, hide or delete comments, like or unlike posts/comments, and read page insights. Actions: page, list_posts, get_post, create_post, delete_post, list_comments, comment, reply, delete_comment, hide_comment, like, unlike, insights. '
            .CredentialsFile::hint().' (keys: FACEBOOK_ACCESS_TOKEN, FACEBOOK_PAGE_ID). Pass access_token (a system user / admin token with access to the page) and page_id with the call, or omit them and the tool reads them from that file. Never print, log, or paste the token. Read actions use the token directly; write actions (create_post, delete_post, comment, reply, delete_comment, hide_comment, like, unlike) first exchange it via the /me/accounts endpoint to obtain the Page Access Token for page_id.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'page', 'list_posts', 'get_post', 'create_post', 'delete_post',
                        'list_comments', 'comment', 'reply', 'delete_comment',
                        'hide_comment', 'like', 'unlike', 'insights',
                    ],
                    'description' => 'The Facebook operation to perform.',
                ],
                'access_token' => ['type' => 'string', 'description' => 'Facebook access token with access to the target page (system user or admin token that can list pages via /me/accounts). Optional: defaults to FACEBOOK_ACCESS_TOKEN in the credentials file ('.CredentialsFile::path().').'],
                'page_id' => ['type' => 'string', 'description' => 'Facebook Page ID. Optional: defaults to FACEBOOK_PAGE_ID in the credentials file ('.CredentialsFile::path().').'],
                'post_id' => ['type' => 'string', 'description' => 'Post ID (get_post, delete_post, or as the target object).'],
                'object_id' => ['type' => 'string', 'description' => 'Object to act on — a post or comment ID (list_comments, comment, like, unlike). Falls back to post_id.'],
                'comment_id' => ['type' => 'string', 'description' => 'Comment ID (reply, delete_comment, hide_comment).'],
                'message' => ['type' => 'string', 'description' => 'Text used by create_post, comment, and reply.'],
                'link' => ['type' => 'string', 'description' => 'Optional URL to attach when creating a post.'],
                'asset' => ['type' => 'string', 'description' => 'Optional media-library clip (slug or filename) this post publishes. When set, a successful create_post marks that clip as posted so it is not posted twice. Check media_clip action=library for unposted clips.'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 25, max 100).'],
                'after' => ['type' => 'string', 'description' => 'Pagination cursor returned as "Next cursor" by a previous list call.'],
                'is_hidden' => ['type' => 'boolean', 'description' => 'hide_comment: true to hide the comment, false to unhide it.'],
                'metric' => ['type' => 'string', 'description' => 'Comma-separated metrics for insights, e.g. page_impressions,page_engaged_users.'],
                'period' => ['type' => 'string', 'description' => 'Insights period: day, week, days_28, month, or lifetime.'],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_facebook', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $this->accessToken = $this->resolveAccessToken($arguments);

        return match ($arguments['action'] ?? null) {
            'page' => $this->page($this->pageId($arguments)),
            'list_posts' => $this->listPosts(
                $this->pageId($arguments),
                $this->limit($arguments),
                $this->string($arguments, 'after'),
            ),
            'get_post' => $this->getPost($this->id($arguments, 'post_id')),
            'create_post' => $this->createPost(
                $this->pageId($arguments),
                $this->requiredMessage($arguments),
                $this->string($arguments, 'link'),
                $this->pageAccessToken($this->pageId($arguments)),
                $this->string($arguments, 'asset'),
            ),
            'delete_post' => $this->deleteObject(
                $this->id($arguments, 'post_id'),
                'Post',
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'list_comments' => $this->listComments(
                $this->id($arguments, 'object_id', 'post_id'),
                $this->limit($arguments),
                $this->string($arguments, 'after'),
            ),
            'comment' => $this->comment(
                $this->id($arguments, 'object_id', 'post_id'),
                $this->requiredMessage($arguments),
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'reply' => $this->reply(
                $this->id($arguments, 'comment_id'),
                $this->requiredMessage($arguments),
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'delete_comment' => $this->deleteObject(
                $this->id($arguments, 'comment_id'),
                'Comment',
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'hide_comment' => $this->hideComment(
                $this->id($arguments, 'comment_id'),
                (bool) ($arguments['is_hidden'] ?? true),
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'like' => $this->like(
                $this->id($arguments, 'object_id', 'post_id'),
                true,
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'unlike' => $this->like(
                $this->id($arguments, 'object_id', 'post_id'),
                false,
                $this->pageAccessToken($this->pageId($arguments)),
            ),
            'insights' => $this->insights(
                $this->pageId($arguments),
                $this->string($arguments, 'metric'),
                $this->string($arguments, 'period') ?: 'day',
            ),
            default => throw new RuntimeException('Unsupported facebook action. Use one of: page, list_posts, get_post, create_post, delete_post, list_comments, comment, reply, delete_comment, hide_comment, like, unlike, insights.'),
        };
    }

    private function page(string $pageId): string
    {
        $data = $this->request('GET', "/{$pageId}", [
            'fields' => 'id,name,category,about,link,website,fan_count,followers_count',
        ]);

        return $this->formatPage($data);
    }

    private function listPosts(string $pageId, int $limit, string $after): string
    {
        $data = $this->request('GET', "/{$pageId}/posts", $this->withCursor([
            'fields' => 'id,message,story,created_time,permalink_url,full_picture,shares,comments.summary(true),reactions.summary(true)',
            'limit' => $limit,
        ], $after));

        return $this->formatCollection($data, 'posts', fn (array $post): string => $this->formatPost($post));
    }

    private function getPost(string $postId): string
    {
        $data = $this->request('GET', "/{$postId}", [
            'fields' => 'id,message,story,created_time,permalink_url,full_picture,shares,comments.summary(true),reactions.summary(true)',
        ]);

        return $this->formatPost($data);
    }

    private function createPost(string $pageId, string $message, string $link, string $pageToken, string $asset = ''): string
    {
        $payload = ['message' => $message];

        if ($link !== '') {
            $payload['link'] = $link;
        }

        $data = $this->request('POST', "/{$pageId}/feed", $payload, $pageToken);

        $postId = (string) ($data['id'] ?? '');

        if ($asset !== '' && $postId !== '') {
            $library = app(MediaLibrary::class);
            $tracked = $library->resolve($asset);

            if ($tracked !== null) {
                $library->markPosted(
                    $tracked,
                    'facebook',
                    $postId,
                    'https://www.facebook.com/'.$postId,
                );
            }
        }

        return 'Published post. ID: '.($postId !== '' ? $postId : '(unknown)');
    }

    private function listComments(string $objectId, int $limit, string $after): string
    {
        $data = $this->request('GET', "/{$objectId}/comments", $this->withCursor([
            'fields' => 'id,message,from,created_time,like_count,comment_count',
            'limit' => $limit,
        ], $after));

        return $this->formatCollection($data, 'comments', fn (array $comment): string => $this->formatComment($comment));
    }

    private function comment(string $objectId, string $message, string $pageToken): string
    {
        $data = $this->request('POST', "/{$objectId}/comments", ['message' => $message], $pageToken);

        return 'Posted comment. ID: '.($data['id'] ?? '(unknown)');
    }

    private function reply(string $commentId, string $message, string $pageToken): string
    {
        $data = $this->request('POST', "/{$commentId}/comments", ['message' => $message], $pageToken);

        return 'Posted reply. ID: '.($data['id'] ?? '(unknown)');
    }

    private function hideComment(string $commentId, bool $hidden, string $pageToken): string
    {
        $this->request('POST', "/{$commentId}", ['is_hidden' => $hidden ? 'true' : 'false'], $pageToken);

        return $hidden
            ? 'Comment hidden.'
            : 'Comment unhidden.';
    }

    private function like(string $objectId, bool $like, string $pageToken): string
    {
        if ($like) {
            $this->request('POST', "/{$objectId}/likes", [], $pageToken);

            return 'Liked object.';
        }

        $this->request('DELETE', "/{$objectId}/likes", [], $pageToken);

        return 'Removed like from object.';
    }

    private function deleteObject(string $objectId, string $label, string $pageToken): string
    {
        $data = $this->request('DELETE', "/{$objectId}", [], $pageToken);

        if (($data['success'] ?? true) === true) {
            return "{$label} {$objectId} deleted.";
        }

        return "{$label} delete returned an unexpected response: ".json_encode($data);
    }

    private function insights(string $pageId, string $metric, string $period): string
    {
        if ($metric === '') {
            throw new RuntimeException('A metric is required for insights, e.g. page_impressions,page_engaged_users.');
        }

        $data = $this->request('GET', "/{$pageId}/insights", [
            'metric' => $metric,
            'period' => $period,
        ]);

        $rows = $data['data'] ?? [];

        if ($rows === []) {
            return 'No insights returned.';
        }

        $output = collect($rows)->map(function (array $metric): string {
            $values = collect($metric['values'] ?? [])->map(function ($entry): string {
                $value = is_array($entry) ? ($entry['value'] ?? '') : $entry;
                $end = is_array($entry) ? ($entry['end_time'] ?? '') : '';

                return trim(($end !== '' ? $end.': ' : '').(is_array($value) ? json_encode($value) : (string) $value));
            })->implode(', ');

            return ($metric['title'] ?? $metric['name'] ?? 'metric')." ({$metric['period']}): {$values}";
        })->implode("\n");

        return $this->truncate($output, 100);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function pageId(array $arguments): string
    {
        $pageId = trim((string) ($arguments['page_id'] ?? ''));

        if ($pageId === '') {
            $pageId = (string) CredentialsFile::get('FACEBOOK_PAGE_ID', '');
        }

        if ($pageId === '') {
            throw new RuntimeException('No Facebook Page ID provided. Pass page_id or set FACEBOOK_PAGE_ID in '.CredentialsFile::path().'.');
        }

        return $pageId;
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
    private function id(array $arguments, string $key, ?string $fallbackKey = null): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));

        if ($value === '' && $fallbackKey !== null) {
            $value = trim((string) ($arguments[$fallbackKey] ?? ''));
        }

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
        $limit = (int) ($arguments['limit'] ?? 25);

        return max(1, min(100, $limit));
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
    private function request(string $method, string $path, array $params = [], ?string $token = null): array
    {
        $url = rtrim((string) config('services.facebook.graph_url', 'https://graph.facebook.com/v21.0'), '/').'/'.ltrim($path, '/');
        $http = Http::withToken($token ?? $this->accessToken)->acceptJson()->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $params),
            'POST' => $http->asForm()->post($url, $params),
            'DELETE' => $http->delete($url, $params),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}."),
        };

        $data = $response->json();

        if ($response->failed()) {
            $message = is_array($data) && isset($data['error']['message'])
                ? (string) $data['error']['message']
                : $response->body();

            throw new RuntimeException("Facebook Graph API request failed: {$message}");
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
            $token = (string) CredentialsFile::get('FACEBOOK_ACCESS_TOKEN', '');
        }

        if ($token === '') {
            throw new RuntimeException('A Facebook access token is required. Pass access_token or set FACEBOOK_ACCESS_TOKEN in '.CredentialsFile::path().'.');
        }

        return $token;
    }

    /**
     * Exchange the user/system token for the Page Access Token via /me/accounts.
     */
    private function pageAccessToken(string $pageId): string
    {
        if (isset($this->pageTokens[$pageId])) {
            return $this->pageTokens[$pageId];
        }

        $token = '';
        $after = '';

        do {
            $params = ['fields' => 'id,access_token', 'limit' => 100];

            if ($after !== '') {
                $params['after'] = $after;
            }

            $data = $this->request('GET', '/me/accounts', $params);

            foreach ($data['data'] ?? [] as $page) {
                if ((string) ($page['id'] ?? '') === $pageId) {
                    $token = (string) ($page['access_token'] ?? '');
                    break 2;
                }
            }

            $after = (string) ($data['paging']['cursors']['after'] ?? '');
            $hasNext = ! empty($data['paging']['next']);
        } while ($hasNext && $after !== '');

        if ($token === '') {
            throw new RuntimeException("Could not obtain a Page Access Token for page {$pageId} via /me/accounts. Ensure the token can manage this page and has the pages_manage_posts and pages_read_engagement permissions.");
        }

        return $this->pageTokens[$pageId] = $token;
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
     * @param  array<string, mixed>  $page
     */
    private function formatPage(array $page): string
    {
        $lines = ['Page: '.($page['name'] ?? '(unknown)'), 'ID: '.($page['id'] ?? '(unknown)')];

        foreach (['category' => 'Category', 'about' => 'About', 'link' => 'Link', 'website' => 'Website'] as $key => $label) {
            if (! empty($page[$key])) {
                $lines[] = "{$label}: {$page[$key]}";
            }
        }

        if (isset($page['fan_count'])) {
            $lines[] = 'Fans: '.$page['fan_count'];
        }

        if (isset($page['followers_count'])) {
            $lines[] = 'Followers: '.$page['followers_count'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function formatPost(array $post): string
    {
        $lines = ['Post ID: '.($post['id'] ?? '(unknown)')];

        if (! empty($post['created_time'])) {
            $lines[] = 'Created: '.$post['created_time'];
        }

        if (! empty($post['message'])) {
            $lines[] = 'Message: '.$this->snippet((string) $post['message']);
        }

        if (! empty($post['story'])) {
            $lines[] = 'Story: '.$this->snippet((string) $post['story']);
        }

        if (! empty($post['permalink_url'])) {
            $lines[] = 'Permalink: '.$post['permalink_url'];
        }

        if (! empty($post['full_picture'])) {
            $lines[] = 'Image: '.$post['full_picture'];
        }

        if (isset($post['comments']['summary']['total_count'])) {
            $lines[] = 'Comments: '.$post['comments']['summary']['total_count'];
        }

        if (isset($post['reactions']['summary']['total_count'])) {
            $lines[] = 'Reactions: '.$post['reactions']['summary']['total_count'];
        }

        if (isset($post['shares']['count'])) {
            $lines[] = 'Shares: '.$post['shares']['count'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    private function formatComment(array $comment): string
    {
        $author = $comment['from']['name'] ?? 'Unknown';

        $lines = [
            'Comment ID: '.($comment['id'] ?? '(unknown)'),
            'Author: '.$author,
        ];

        if (! empty($comment['created_time'])) {
            $lines[] = 'Created: '.$comment['created_time'];
        }

        $lines[] = 'Message: '.$this->snippet((string) ($comment['message'] ?? ''));
        $lines[] = 'Likes: '.($comment['like_count'] ?? 0).' | Replies: '.($comment['comment_count'] ?? 0);

        return implode("\n", $lines);
    }

    private function snippet(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > 400 ? mb_substr($value, 0, 400).'…' : $value;
    }
}
