<?php

namespace Tests\Feature;

use App\Http\Requests\SendConversationMessageRequest;
use App\Http\Requests\StoreConversationRequest;
use Tests\TestCase;

class ConversationApiTest extends TestCase
{
    public function test_send_conversation_message_request_has_expected_rules(): void
    {
        $request = new SendConversationMessageRequest();

        $this->assertSame([
            'message' => ['required', 'string', 'max:10000'],
        ], $request->rules());
    }

    public function test_store_conversation_request_has_expected_optional_fields(): void
    {
        $request = new StoreConversationRequest();

        $this->assertSame([
            'title' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
        ], $request->rules());
    }
}
