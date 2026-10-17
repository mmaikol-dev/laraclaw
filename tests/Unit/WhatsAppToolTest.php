<?php

namespace Tests\Unit;

use App\Models\AgentSetting;
use App\Services\Agent\ToolRegistry;
use App\Services\Tools\WhatsAppTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WhatsAppToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whatsapp_tool_is_registered(): void
    {
        $this->assertInstanceOf(WhatsAppTool::class, app(ToolRegistry::class)->get('whatsapp'));
    }

    public function test_it_sends_a_text_message(): void
    {
        Http::fake([
            '127.0.0.1:2785/api/sessions/session-1/messages/send-text' => Http::response([
                'messageId' => '3EB0D5EF1DCD4FB4',
                'timestamp' => 1784050369,
            ], 201),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'send_text',
            'api_key' => 'owa_test_key',
            'session_id' => 'session-1',
            'chat_id' => '628123456789',
            'text' => 'Hello from LaraClaw',
        ]);

        $this->assertSame('Message sent. ID: 3EB0D5EF1DCD4FB4', $result);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:2785/api/sessions/session-1/messages/send-text'
            && $request->hasHeader('X-API-Key', 'owa_test_key')
            && $request['chatId'] === '628123456789@c.us'
            && $request['text'] === 'Hello from LaraClaw');
    }

    public function test_it_keeps_an_explicit_jid(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['messageId' => 'm1'], 201),
        ]);

        (new WhatsAppTool)->execute([
            'action' => 'send_text',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '120363000000000000@g.us',
            'text' => 'Team update',
        ]);

        Http::assertSent(fn (Request $request): bool => $request['chatId'] === '120363000000000000@g.us');
    }

    public function test_it_treats_an_internal_server_error_on_send_as_sent(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['statusCode' => 500, 'message' => 'Internal server error'], 500),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'send_text',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628123456789',
            'text' => 'Are you there?',
        ]);

        $this->assertStringContainsString('Message accepted', $result);
        $this->assertStringContainsString('500', $result);
    }

    public function test_it_sends_an_image_with_a_caption(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['messageId' => 'img-1'], 201),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'send_image',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628123456789',
            'url' => 'https://example.com/photo.jpg',
            'caption' => 'Look at this',
        ]);

        $this->assertSame('Message sent. ID: img-1', $result);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages/send-image')
            && $request['url'] === 'https://example.com/photo.jpg'
            && $request['caption'] === 'Look at this');
    }

    public function test_it_sends_a_document_from_base64(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['messageId' => 'doc-1'], 201),
        ]);

        (new WhatsAppTool)->execute([
            'action' => 'send_document',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628123456789',
            'base64' => 'SGVsbG8=',
            'mimetype' => 'application/pdf',
            'filename' => 'report.pdf',
        ]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages/send-document')
            && $request['base64'] === 'SGVsbG8='
            && $request['mimetype'] === 'application/pdf'
            && $request['filename'] === 'report.pdf');
    }

    public function test_it_requires_media_for_send_image(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('send-image requires a url or base64');

        (new WhatsAppTool)->execute([
            'action' => 'send_image',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628123456789',
        ]);
    }

    public function test_it_sends_a_location_pin(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['messageId' => 'loc-1'], 201),
        ]);

        (new WhatsAppTool)->execute([
            'action' => 'send_location',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628123456789',
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'description' => 'Jakarta',
        ]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages/send-location')
            && (float) $request['latitude'] === -6.2088
            && (float) $request['longitude'] === 106.8456
            && $request['description'] === 'Jakarta');
    }

    public function test_it_checks_whether_a_number_is_on_whatsapp(): void
    {
        Http::fake([
            '127.0.0.1:2785/api/sessions/s/contacts/check/628123456789' => Http::response([
                'number' => '628123456789',
                'exists' => true,
                'whatsappId' => '628123456789@c.us',
            ]),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'check_number',
            'api_key' => 'k',
            'session_id' => 's',
            'number' => '+62 812-3456-789',
        ]);

        $this->assertStringContainsString('is on WhatsApp', $result);
        $this->assertStringContainsString('628123456789@c.us', $result);
    }

    public function test_it_reports_a_number_that_is_not_on_whatsapp(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['number' => '628000', 'exists' => false, 'whatsappId' => null]),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'check_number',
            'api_key' => 'k',
            'session_id' => 's',
            'number' => '628000',
        ]);

        $this->assertStringContainsString('not registered on WhatsApp', $result);
    }

    public function test_it_reads_a_session_status(): void
    {
        Http::fake([
            '127.0.0.1:2785/api/sessions/s' => Http::response([
                'id' => 's',
                'name' => 'main',
                'status' => 'ready',
                'phone' => '628123456789',
            ]),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'session',
            'api_key' => 'k',
            'session_id' => 's',
        ]);

        $this->assertStringContainsString('Session: main', $result);
        $this->assertStringContainsString('Status: ready', $result);
        $this->assertStringContainsString('Phone: 628123456789', $result);
    }

    public function test_it_lists_sessions(): void
    {
        Http::fake([
            '127.0.0.1:2785/api/sessions*' => Http::response([
                ['id' => 's1', 'name' => 'main', 'status' => 'ready'],
                ['id' => 's2', 'name' => 'backup', 'status' => 'initializing'],
            ]),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'list_sessions',
            'api_key' => 'k',
        ]);

        $this->assertStringContainsString('Session: main', $result);
        $this->assertStringContainsString('Session: backup', $result);
    }

    public function test_it_lists_messages_in_a_chat(): void
    {
        Http::fake([
            '127.0.0.1:2785/api/sessions/s/messages*' => Http::response([
                'messages' => [
                    ['id' => 'm1', 'body' => 'Hi there', 'fromMe' => false, 'from' => '628111@c.us', 'timestamp' => 1784050369],
                ],
                'total' => 1,
            ]),
        ]);

        $result = (new WhatsAppTool)->execute([
            'action' => 'list_messages',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628111',
        ]);

        $this->assertStringContainsString('Hi there', $result);
        $this->assertStringContainsString('628111@c.us', $result);
    }

    public function test_it_surfaces_gateway_errors(): void
    {
        Http::fake([
            '127.0.0.1:2785/*' => Http::response(['statusCode' => 409, 'message' => 'Session is not ready'], 409),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Session is not ready');

        (new WhatsAppTool)->execute([
            'action' => 'send_text',
            'api_key' => 'k',
            'session_id' => 's',
            'chat_id' => '628123456789',
            'text' => 'Hi',
        ]);
    }

    public function test_it_requires_an_api_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A WhatsApp API key is required');

        (new WhatsAppTool)->execute([
            'action' => 'session',
            'session_id' => 's',
        ]);
    }

    public function test_it_requires_a_session_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No WhatsApp session id provided');

        (new WhatsAppTool)->execute([
            'action' => 'session',
            'api_key' => 'k',
        ]);
    }

    public function test_it_reads_credentials_from_the_credentials_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'wa-creds-');
        file_put_contents($file, "OPENWA_API_KEY=key-from-file\nOPENWA_SESSION_ID=session-from-file\n");
        config()->set('agent.credentials_file', $file);

        Http::fake([
            '127.0.0.1:2785/api/sessions/session-from-file' => Http::response(['id' => 'session-from-file', 'name' => 'from-file', 'status' => 'ready']),
        ]);

        try {
            $result = (new WhatsAppTool)->execute(['action' => 'session']);
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('from-file', $result);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-Key', 'key-from-file'));
    }

    public function test_settings_can_disable_the_tool(): void
    {
        AgentSetting::query()->create([
            'key' => 'enable_whatsapp',
            'value' => 'false',
            'type' => 'bool',
            'label' => 'Enable WhatsApp',
            'description' => 'test',
        ]);

        $this->assertFalse((new WhatsAppTool)->isEnabled());
    }

    public function test_the_tool_description_points_at_the_credentials_file(): void
    {
        $description = (new WhatsAppTool)->getDescription();

        $this->assertStringContainsString(config('agent.credentials_file'), $description);
        $this->assertStringContainsString('OPENWA_API_KEY', $description);
        $this->assertStringContainsString('OPENWA_SESSION_ID', $description);
    }
}
