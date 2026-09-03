<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\Message;
use PHPUnit\Framework\TestCase;

class ConversationFormattingTest extends TestCase
{
    public function test_message_formats_tool_calls_for_ollama(): void
    {
        $message = new Message([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['function' => ['name' => 'read_file', 'arguments' => ['path' => '/tmp/file.txt']]],
            ],
        ]);

        $this->assertSame([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['function' => ['name' => 'read_file', 'arguments' => ['path' => '/tmp/file.txt']]],
            ],
        ], $message->toOllamaFormat());
    }

    public function test_conversation_filters_system_messages_from_ollama_history(): void
    {
        $conversation = new Conversation();
        $conversation->setRelation('messages', collect([
            new Message(['role' => 'system', 'content' => 'hidden']),
            new Message(['role' => 'user', 'content' => 'Hello']),
            new Message(['role' => 'assistant', 'content' => 'Hi there']),
            new Message(['role' => 'tool', 'content' => 'File list', 'tool_name' => 'list_files']),
        ]));

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'tool', 'content' => 'File list'],
        ], $conversation->toOllamaMessages());
    }
}
