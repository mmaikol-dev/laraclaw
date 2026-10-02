<?php

namespace Tests\Unit;

use App\Models\Conversation;
use Tests\TestCase;

class ConversationModelDefaultTest extends TestCase
{
    public function test_it_falls_back_to_the_configured_agent_model(): void
    {
        config(['ollama.agent_model' => 'gemma4:cloud']);

        $conversation = Conversation::create(['title' => 'probe']);

        $this->assertSame('gemma4:cloud', $conversation->model);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'model' => 'gemma4:cloud']);
    }

    public function test_it_never_leaves_the_model_blank(): void
    {
        config(['ollama.agent_model' => 'gemma4:cloud']);

        foreach ([null, '', '   '] as $blank) {
            $conversation = Conversation::create(['title' => 'probe', 'model' => $blank]);

            $this->assertSame('gemma4:cloud', $conversation->model);
        }
    }

    public function test_it_preserves_an_explicitly_routed_model(): void
    {
        config(['ollama.agent_model' => 'gemma4:cloud']);

        $conversation = Conversation::create(['title' => 'probe', 'model' => 'qwen3:4b']);

        $this->assertSame('qwen3:4b', $conversation->model);
    }

    public function test_a_conversation_never_inherits_a_retired_model_default(): void
    {
        // The conversations.model column default is a retired cloud model, so an
        // unset model must never fall through to it.
        config(['ollama.agent_model' => 'gemma4:cloud']);

        $conversation = Conversation::create(['title' => 'probe']);

        $this->assertNotSame('glm-5:cloud', $conversation->model);
    }
}
