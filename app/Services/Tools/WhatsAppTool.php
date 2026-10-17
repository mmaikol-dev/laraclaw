<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppTool extends BaseTool
{
    private string $apiKey = '';

    private string $baseUrl = '';

    public function getName(): string
    {
        return 'whatsapp';
    }

    public function getDescription(): string
    {
        return 'Send WhatsApp messages through a locally hosted OpenWA (Wazuri) gateway to contact people directly. Send text, an image, a document, or a location pin to a chat; read recent messages in a chat; check whether a phone number is registered on WhatsApp before messaging it; and read the configured session status or the list of sessions. Actions: session, list_sessions, check_number, list_messages, send_text, send_image, send_document, send_location. '
            .CredentialsFile::hint().' (keys: OPENWA_API_KEY, OPENWA_SESSION_ID, OPENWA_BASE_URL). Pass api_key and session_id with the call, or omit them and the tool reads them from that file (falling back to config). Never print, log, or paste the API key. '
            .'A chat_id is a phone number (e.g. 628123456789) or a WhatsApp JID (e.g. 628123456789@c.us for a person, 1203...@g.us for a group); a plain number is turned into a @c.us JID for you. Because OpenWA sometimes dispatches a message and then reports an internal server error, a send that returns HTTP 500 or another 5xx is reported as sent rather than failed.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'session', 'list_sessions', 'check_number', 'list_messages',
                        'send_text', 'send_image', 'send_document', 'send_location',
                    ],
                    'description' => 'The WhatsApp operation to perform.',
                ],
                'api_key' => ['type' => 'string', 'description' => 'OpenWA API key (operator or admin). Optional: defaults to OPENWA_API_KEY in the credentials file ('.CredentialsFile::path().'), then to config services.openwa.api_key.'],
                'session_id' => ['type' => 'string', 'description' => 'OpenWA session UUID. Optional: defaults to OPENWA_SESSION_ID in the credentials file ('.CredentialsFile::path().'), then to config services.openwa.session_id.'],
                'base_url' => ['type' => 'string', 'description' => 'OpenWA API base URL including the /api suffix. Optional: defaults to OPENWA_BASE_URL in the credentials file, then to config services.openwa.base_url.'],
                'chat_id' => ['type' => 'string', 'description' => 'Recipient chat: a phone number (628123456789) or WhatsApp JID (628123456789@c.us, or a group id ending @g.us). Required for send_text, send_image, send_document, send_location, and list_messages.'],
                'text' => ['type' => 'string', 'description' => 'Message text (send_text, max 4096 characters).'],
                'url' => ['type' => 'string', 'description' => 'Publicly reachable HTTP(S) URL of the media to send (send_image, send_document). Provide this or base64.'],
                'base64' => ['type' => 'string', 'description' => 'Base64-encoded media to send (send_image, send_document). Provide this or url; mimetype is required with base64.'],
                'mimetype' => ['type' => 'string', 'description' => 'Media MIME type, e.g. image/jpeg or application/pdf. Required when sending base64.'],
                'filename' => ['type' => 'string', 'description' => 'Filename to show for the media (send_image, send_document).'],
                'caption' => ['type' => 'string', 'description' => 'Caption to accompany the media or image (send_image, send_document).'],
                'latitude' => ['type' => 'number', 'description' => 'Latitude of the location pin (send_location, -90 to 90).'],
                'longitude' => ['type' => 'number', 'description' => 'Longitude of the location pin (send_location, -180 to 180).'],
                'description' => ['type' => 'string', 'description' => 'Label for the location pin (send_location).'],
                'address' => ['type' => 'string', 'description' => 'Street address for the location pin (send_location).'],
                'number' => ['type' => 'string', 'description' => 'Phone number to test for a WhatsApp account, digits only (check_number).'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of items to return (default 25, max 100).'],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_whatsapp', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $this->apiKey = $this->resolveApiKey($arguments);
        $this->baseUrl = $this->resolveBaseUrl($arguments);

        return match ($arguments['action'] ?? null) {
            'session' => $this->session($this->sessionId($arguments)),
            'list_sessions' => $this->listSessions($this->limit($arguments)),
            'check_number' => $this->checkNumber(
                $this->sessionId($arguments),
                $this->requiredString($arguments, 'number', 'A phone number is required for check_number.'),
            ),
            'list_messages' => $this->listMessages(
                $this->sessionId($arguments),
                $this->chatId($arguments),
                $this->limit($arguments),
            ),
            'send_text' => $this->sendText(
                $this->sessionId($arguments),
                $this->chatId($arguments),
                $this->requiredString($arguments, 'text', 'A text is required for send_text.'),
            ),
            'send_image' => $this->sendMedia($this->sessionId($arguments), $this->chatId($arguments), 'send-image', $arguments),
            'send_document' => $this->sendMedia($this->sessionId($arguments), $this->chatId($arguments), 'send-document', $arguments),
            'send_location' => $this->sendLocation($this->sessionId($arguments), $this->chatId($arguments), $arguments),
            default => throw new RuntimeException('Unsupported whatsapp action. Use one of: session, list_sessions, check_number, list_messages, send_text, send_image, send_document, send_location.'),
        };
    }

    private function session(string $sessionId): string
    {
        return $this->formatSession($this->request('GET', "/sessions/{$sessionId}"));
    }

    private function listSessions(int $limit): string
    {
        $data = $this->request('GET', '/sessions', ['limit' => $limit]);
        $sessions = $data['data'] ?? $data;

        if (! is_array($sessions) || $sessions === []) {
            return 'No WhatsApp sessions found.';
        }

        $lines = [];

        foreach ($sessions as $session) {
            if (is_array($session)) {
                $lines[] = $this->formatSession($session);
            }
        }

        return $this->truncate(implode("\n\n---\n\n", $lines), 300);
    }

    private function checkNumber(string $sessionId, string $number): string
    {
        $number = preg_replace('/\D+/', '', $number) ?? '';

        if ($number === '') {
            throw new RuntimeException('check_number requires a valid phone number (digits only).');
        }

        $data = $this->request('GET', "/sessions/{$sessionId}/contacts/check/{$number}");

        $exists = (bool) ($data['exists'] ?? false);
        $whatsappId = (string) ($data['whatsappId'] ?? '');

        if ($exists) {
            return "{$number} is on WhatsApp.".($whatsappId !== '' ? " WhatsApp ID: {$whatsappId}." : '');
        }

        return "{$number} is not registered on WhatsApp. Do not message it.";
    }

    private function listMessages(string $sessionId, string $chatId, int $limit): string
    {
        $data = $this->request('GET', "/sessions/{$sessionId}/messages", [
            'chatId' => $chatId,
            'limit' => $limit,
        ]);

        $messages = $data['messages'] ?? [];

        if (! is_array($messages) || $messages === []) {
            return "No messages found for {$chatId}.";
        }

        $lines = [];

        foreach ($messages as $message) {
            if (is_array($message)) {
                $lines[] = $this->formatMessage($message);
            }
        }

        return $this->truncate(implode("\n", $lines), 300);
    }

    private function sendText(string $sessionId, string $chatId, string $text): string
    {
        return $this->send("/sessions/{$sessionId}/messages/send-text", [
            'chatId' => $chatId,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function sendMedia(string $sessionId, string $chatId, string $endpoint, array $arguments): string
    {
        $url = $this->string($arguments, 'url');
        $base64 = $this->string($arguments, 'base64');

        if ($url === '' && $base64 === '') {
            throw new RuntimeException("{$endpoint} requires a url or base64 media payload.");
        }

        $payload = ['chatId' => $chatId];

        if ($url !== '') {
            $payload['url'] = $url;
        }

        if ($base64 !== '') {
            $payload['base64'] = $base64;
        }

        foreach (['mimetype', 'filename', 'caption'] as $key) {
            $value = $this->string($arguments, $key);

            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        return $this->send("/sessions/{$sessionId}/messages/{$endpoint}", $payload);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function sendLocation(string $sessionId, string $chatId, array $arguments): string
    {
        $payload = [
            'chatId' => $chatId,
            'latitude' => $this->requiredFloat($arguments, 'latitude'),
            'longitude' => $this->requiredFloat($arguments, 'longitude'),
        ];

        foreach (['description', 'address'] as $key) {
            $value = $this->string($arguments, $key);

            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        return $this->send("/sessions/{$sessionId}/messages/send-location", $payload);
    }

    /**
     * Send a message and return a human-readable result.
     *
     * OpenWA (Baileys / whatsapp-web.js) sometimes hands the message to the
     * WhatsApp client and only then fails server-side, surfacing a 5xx. The
     * message has already left the gateway in that case, so a server error is
     * reported as sent rather than raised as a failure.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(string $path, array $payload): string
    {
        $response = $this->http()->post($this->url($path), $payload);
        $data = $response->json();

        if ($response->serverError()) {
            return 'Message accepted. The gateway returned HTTP '.$response->status().' (internal server error), but the message was sent.';
        }

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp send failed: '.$this->errorMessage($response, $data));
        }

        $messageId = is_array($data) ? (string) ($data['messageId'] ?? '') : '';

        return $messageId !== '' ? "Message sent. ID: {$messageId}" : 'Message sent.';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params = []): array
    {
        $http = $this->http();
        $url = $this->url($path);

        $response = strtoupper($method) === 'GET'
            ? $http->get($url, $params)
            : $http->post($url, $params);

        $data = $response->json();

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp gateway request failed: '.$this->errorMessage($response, $data));
        }

        return is_array($data) ? $data : [];
    }

    private function http(): PendingRequest
    {
        $http = Http::acceptJson()->timeout(30);

        if ($this->apiKey !== '') {
            $http = $http->withHeaders(['X-API-Key' => $this->apiKey]);
        }

        return $http;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function sessionId(array $arguments): string
    {
        $sessionId = trim((string) ($arguments['session_id'] ?? ''));

        if ($sessionId === '') {
            $sessionId = (string) CredentialsFile::get('OPENWA_SESSION_ID', '');
        }

        if ($sessionId === '') {
            $sessionId = (string) config('services.openwa.session_id', '');
        }

        if ($sessionId === '') {
            throw new RuntimeException('No WhatsApp session id provided. Pass session_id, set OPENWA_SESSION_ID in '.CredentialsFile::path().', or set OPENWA_SESSION_ID / services.openwa.session_id.');
        }

        return $sessionId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function chatId(array $arguments): string
    {
        $chatId = trim((string) ($arguments['chat_id'] ?? ''));

        if ($chatId === '') {
            throw new RuntimeException('A chat_id is required for this action. Use a phone number (628123456789) or a WhatsApp JID (628123456789@c.us).');
        }

        if (str_contains($chatId, '@')) {
            return $chatId;
        }

        $digits = preg_replace('/\D+/', '', $chatId) ?? '';

        if ($digits === '') {
            throw new RuntimeException("Invalid chat_id: {$chatId}. Use a phone number or a WhatsApp JID.");
        }

        return $digits.'@c.us';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveApiKey(array $arguments): string
    {
        $key = trim((string) ($arguments['api_key'] ?? ''));

        if ($key === '') {
            $key = (string) CredentialsFile::get('OPENWA_API_KEY', '');
        }

        if ($key === '') {
            $key = (string) config('services.openwa.api_key', '');
        }

        if ($key === '') {
            throw new RuntimeException('A WhatsApp API key is required. Pass api_key, set OPENWA_API_KEY in '.CredentialsFile::path().', or set OPENWA_API_KEY / services.openwa.api_key.');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveBaseUrl(array $arguments): string
    {
        $baseUrl = trim((string) ($arguments['base_url'] ?? ''));

        if ($baseUrl === '') {
            $baseUrl = (string) CredentialsFile::get('OPENWA_BASE_URL', '');
        }

        if ($baseUrl === '') {
            $baseUrl = (string) config('services.openwa.base_url', 'http://127.0.0.1:2785/api');
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredString(array $arguments, string $key, string $message): string
    {
        $value = $this->string($arguments, $key);

        if ($value === '') {
            throw new RuntimeException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredFloat(array $arguments, string $key): float
    {
        if (! isset($arguments[$key]) || ! is_numeric($arguments[$key])) {
            throw new RuntimeException("A numeric {$key} is required for this action.");
        }

        return (float) $arguments[$key];
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
     * @param  array<string, mixed>  $data
     */
    private function errorMessage(mixed $response, mixed $data): string
    {
        if (is_array($data)) {
            foreach (['message', 'error', 'detail'] as $key) {
                if (! empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
        }

        $body = $response instanceof Response ? trim($response->body()) : '';

        return $body !== '' ? $body : 'unknown error';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function formatSession(array $session): string
    {
        $lines = ['Session: '.($session['name'] ?? $session['id'] ?? '(unknown)')];

        foreach (['id' => 'ID', 'status' => 'Status', 'phone' => 'Phone', 'pushName' => 'Account', 'connectedAt' => 'Connected at', 'lastActiveAt' => 'Last active'] as $key => $label) {
            if (isset($session[$key]) && $session[$key] !== '' && $session[$key] !== null) {
                $lines[] = $label.': '.$session[$key];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function formatMessage(array $message): string
    {
        $body = (string) ($message['body'] ?? $message['content'] ?? $message['text'] ?? '');
        $fromMe = (bool) ($message['fromMe'] ?? false);
        $from = (string) ($message['from'] ?? $message['author'] ?? '');
        $who = $fromMe ? 'me' : ($from !== '' ? $from : 'them');
        $timestamp = $message['timestamp'] ?? null;
        $stamp = is_numeric($timestamp) ? date('Y-m-d H:i', (int) $timestamp).' ' : '';

        return "[{$stamp}{$who}] ".($body !== '' ? $this->snippet($body) : '[media]');
    }

    private function snippet(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value;
    }
}
