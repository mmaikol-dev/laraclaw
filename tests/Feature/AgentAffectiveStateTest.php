<?php

namespace Tests\Feature;

use App\Services\Agent\AffectiveStateEngine;
use App\Services\Agent\AffectiveStateStore;
use Tests\TestCase;

class AgentAffectiveStateTest extends TestCase
{
    protected function tearDown(): void
    {
        app(AffectiveStateStore::class)->forget(101);
        app(AffectiveStateStore::class)->forget(202);
        app(AffectiveStateStore::class)->forget(303);

        parent::tearDown();
    }

    public function test_it_seeds_curiosity_and_goal_weighting_for_novel_requests(): void
    {
        $engine = app(AffectiveStateEngine::class);

        $state = $engine->begin(101, 'Analyze this unfamiliar production issue, investigate why it happened, and explore the safest fix.');
        $context = $engine->buildContext(101);

        $this->assertGreaterThanOrEqual(0.45, $state['curiosity_score']);
        $this->assertSame(10, $state['love_weights']['user_goal']);
        $this->assertStringContainsString('Primary user goal', $context);
        $this->assertStringContainsString('Novelty is elevated', $context);
    }

    public function test_it_pauses_destructive_shell_actions_when_fear_crosses_the_threshold(): void
    {
        $engine = app(AffectiveStateEngine::class);
        $engine->begin(202, 'Clean up this project.');

        $shouldPause = $engine->shouldPauseForSafety(202, 'shell', [
            'command' => 'rm -rf /home/atlas/Projects/laraclaw/storage/app',
        ]);

        $this->assertTrue($shouldPause);
        $this->assertStringContainsString('deletes files recursively', $engine->blockMessage(202));
        $this->assertStringContainsString('Caution is elevated', $engine->buildContext(202));
    }

    public function test_it_flags_repeated_failures_and_repetitive_loops(): void
    {
        $engine = app(AffectiveStateEngine::class);
        $engine->begin(303, 'Fix the task.');

        $engine->recordToolResult(303, 'web', false, 'Error: timeout');
        $engine->recordToolResult(303, 'web', false, 'Error: timeout');
        $state = $engine->recordToolResult(303, 'web', false, 'Error: timeout');
        $engine->recordAssistantTurn(303, '', false);
        $context = $engine->buildContext(303);

        $this->assertGreaterThanOrEqual(2, $state['sadness_count']);
        $this->assertGreaterThanOrEqual(2, $state['boredom_counter']);
        $this->assertContains('repeated_failures', $state['guilt_flags']);
        $this->assertContains('empty_response', $engine->get(303)['guilt_flags']);
        $this->assertStringContainsString('Repeated failures were detected', $context);
        $this->assertStringContainsString('Repetition was detected', $context);
    }
}
