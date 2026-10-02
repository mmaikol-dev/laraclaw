<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\TaskEngine\LoopDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopDetectorTest extends TestCase
{
    use RefreshDatabase;

    private LoopDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new LoopDetector;
    }

    public function test_fingerprint_is_stable_for_same_action_and_result(): void
    {
        $this->assertSame(
            $this->detector->fingerprint('read file /tmp/a.php', '/tmp/a.php exists'),
            $this->detector->fingerprint('read file /tmp/a.php', '/tmp/a.php exists')
        );
    }

    public function test_detects_identical_repeated_actions(): void
    {
        $task = Task::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $task->checkpoints()->create([
                'description' => "Attempt {$i}",
                'action_taken' => 'write /tmp/a.php',
                'action_result' => 'wrote file',
            ]);
        }

        $reason = $this->detector->detectLoop($task, 4);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('identical action', $reason);
    }

    public function test_no_loop_when_checkpoints_are_distinct(): void
    {
        $task = Task::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $task->checkpoints()->create([
                'description' => "Step {$i}",
                'action_taken' => "write /tmp/a{$i}.php",
                'action_result' => "wrote file {$i}",
            ]);
        }

        $this->assertNull($this->detector->detectLoop($task, 4));
    }

    public function test_detects_failure_loop_on_same_tool(): void
    {
        $task = Task::factory()->create();

        for ($i = 0; $i < 4; $i++) {
            $task->checkpoints()->create([
                'description' => "Attempt {$i}",
                'action_taken' => "shell retry-variant-{$i}",
                'action_result' => 'Error: network timeout',
            ]);
        }

        $reason = $this->detector->detectLoop($task, 4);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('tool failing repeatedly', $reason);
    }

    public function test_not_enough_checkpoints_returns_null(): void
    {
        $task = Task::factory()->create();

        $task->checkpoints()->create([
            'description' => 'Only one',
            'action_taken' => 'read file',
            'action_result' => 'ok',
        ]);

        $this->assertNull($this->detector->detectLoop($task, 4));
    }
}
