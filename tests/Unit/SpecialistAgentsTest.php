<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\TaskCheckpoint;
use App\Models\TaskStep;
use App\Services\TaskEngine\SpecialistAgents;
use App\Services\TaskEngine\TaskContext;
use PHPUnit\Framework\TestCase;

class SpecialistAgentsTest extends TestCase
{
    private SpecialistAgents $specialists;

    protected function setUp(): void
    {
        parent::setUp();

        $this->specialists = new SpecialistAgents;
    }

    public function test_registers_all_specialist_profiles(): void
    {
        $all = $this->specialists->all();

        $this->assertContains('planner', array_keys($all));
        $this->assertContains('researcher', array_keys($all));
        $this->assertContains('coder', array_keys($all));
        $this->assertContains('debugger', array_keys($all));
        $this->assertContains('tester', array_keys($all));
        $this->assertContains('reviewer', array_keys($all));
        $this->assertContains('security', array_keys($all));
        $this->assertCount(7, $all);
    }

    public function test_every_profile_has_required_fields(): void
    {
        foreach ($this->specialists->all() as $profile) {
            $this->assertNotEmpty($profile->slug);
            $this->assertNotEmpty($profile->name);
            $this->assertNotEmpty($profile->systemPrompt);
            $this->assertNotEmpty($profile->modelTier);
        }
    }

    public function test_resolve_returns_planner_for_planning_steps(): void
    {
        $task = new Task(['goal' => 'Ship the report builder']);

        $this->assertSame('planner', $this->specialists->resolve($task, self::stepWith('Plan the implementation steps'))->slug);
    }

    public function test_resolve_returns_debugger_for_fix_steps(): void
    {
        $task = new Task(['goal' => 'Make the app stable']);

        $this->assertSame('debugger', $this->specialists->resolve($task, self::stepWith('Debug the login crash'))->slug);
    }

    public function test_resolve_returns_tester_for_test_steps(): void
    {
        $task = new Task(['goal' => 'Ship the report builder']);

        $this->assertSame('tester', $this->specialists->resolve($task, self::stepWith('Write PHPUnit tests for the form'))->slug);
    }

    public function test_resolve_returns_security_for_security_steps(): void
    {
        $task = new Task(['goal' => 'Harden the API']);

        $this->assertSame('security', $this->specialists->resolve($task, self::stepWith('Audit for injection vulnerabilities'))->slug);
    }

    public function test_resolve_defaults_to_coder(): void
    {
        $task = new Task(['goal' => 'Ship feature X']);

        $this->assertSame('coder', $this->specialists->resolve($task, self::stepWith('Do a generic task'))->slug);
    }

    public function test_build_prompt_context_includes_role_and_goal(): void
    {
        $specialist = $this->specialists->bySlug('coder');
        $task = new Task(['goal' => 'Add pagination', 'complexity_level' => 'coding']);

        $context = $this->specialists->buildPromptContext($specialist, $task);

        $this->assertStringContainsString('Specialist role: Coder', $context);
        $this->assertStringContainsString('Task complexity: coding', $context);
        $this->assertStringContainsString('Preferred tools', $context);
    }

    public function test_summary_returns_serializable_profiles(): void
    {
        $summary = $this->specialists->summary();

        $this->assertCount(7, $summary);
        $this->assertArrayHasKey('system_prompt', $summary[0]);
        $this->assertArrayHasKey('model_tier', $summary[0]);
    }

    private static function stepWith(string $description)
    {
        $step = new TaskStep;
        $step->description = $description;

        return $step;
    }
}

class TaskContextTest extends TestCase
{
    public function test_build_summary_includes_progress_and_goal(): void
    {
        $task = new Task;
        $task->forceFill([
            'goal' => 'Refactor the auth layer',
            'current_step' => 2,
            'total_steps' => 4,
            'status' => 'running',
            'acceptance_criteria' => ['Auth tests pass'],
            'last_error' => 'Rate limited',
        ]);

        $summary = (new TaskContext)->buildSummary($task);

        $this->assertStringContainsString('Refactor the auth layer', $summary);
        $this->assertStringContainsString('step 2 of 4', $summary);
        $this->assertStringContainsString('Running', $summary);
        $this->assertStringContainsString('Rate limited', $summary);
        $this->assertStringContainsString('Auth tests pass', $summary);
    }

    public function test_build_summary_includes_latest_checkpoint(): void
    {
        $task = new Task;
        $task->forceFill([
            'goal' => 'Refactor the auth layer',
            'current_step' => 2,
            'total_steps' => 4,
            'status' => 'running',
        ]);
        $checkpoint = new TaskCheckpoint([
            'task_id' => 1,
            'step_sort_order' => 2,
            'action_taken' => 'Created AuthGuardRefactor test',
            'action_result' => '12 tests passing',
            'current_step' => 2,
            'task_status' => 'running',
        ]);

        $task->setRelation('latestCheckpoint', $checkpoint);

        $summary = (new TaskContext)->buildSummary($task);

        $this->assertStringContainsString('Last completed action: Created AuthGuardRefactor test', $summary);
        $this->assertStringContainsString('12 tests passing', $summary);
    }

    public function test_snapshot_returns_durable_state(): void
    {
        $task = new Task;
        $task->forceFill([
            'current_step' => 1,
            'total_steps' => 3,
            'status' => 'running',
            'last_error' => null,
        ]);

        $snapshot = (new TaskContext)->snapshot($task);

        $this->assertSame(1, $snapshot['current_step']);
        $this->assertSame(3, $snapshot['total_steps']);
        $this->assertSame(33.3, round($snapshot['progress_percentage'], 1));
        $this->assertArrayHasKey('latest_checkpoint', $snapshot);
        $this->assertNull($snapshot['last_error']);
    }
}
