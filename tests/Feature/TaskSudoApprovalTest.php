<?php

namespace Tests\Feature;

use App\Enums\SudoStatus;
use App\Enums\TaskFailureType;
use App\Enums\TaskStatus;
use App\Jobs\ExecuteTaskStepJob;
use App\Models\Mission;
use App\Models\Task;
use App\Models\User;
use App\Services\Agent\ExecutionScope;
use App\Services\TaskEngine\TaskEngine;
use App\Services\Tools\ShellTool;
use App\Services\Tools\TaskEngineTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TaskSudoApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_create_with_requires_sudo_parks_the_task_for_approval(): void
    {
        $tool = new TaskEngineTool($this->app);

        $output = $tool->execute([
            'action' => 'create',
            'goal' => 'Install the system package',
            'requires_sudo' => true,
            'reason' => 'Needs apt install',
        ]);

        Queue::assertNotPushed(ExecuteTaskStepJob::class);

        $task = Task::query()->sole();

        $this->assertSame(SudoStatus::Requested, $task->sudo_status);
        $this->assertSame('Needs apt install', $task->sudo_reason);
        $this->assertSame(TaskStatus::Waiting, $task->status);
        $this->assertTrue((bool) $task->needs_human_input);
        $this->assertStringContainsString("/agent-tasks/{$task->id}", $output);
    }

    public function test_request_sudo_records_reason_and_parks_a_running_task(): void
    {
        $task = Task::factory()->create(['status' => TaskStatus::Running]);

        $tool = new TaskEngineTool($this->app);

        $output = $tool->execute([
            'action' => 'request_sudo',
            'task_id' => $task->id,
            'reason' => 'Restart nginx',
        ]);

        $task->refresh();

        $this->assertSame(SudoStatus::Requested, $task->sudo_status);
        $this->assertSame('Restart nginx', $task->sudo_reason);
        $this->assertSame(TaskStatus::Waiting, $task->status);
        $this->assertTrue((bool) $task->needs_human_input);
        $this->assertStringContainsString('Sudo approval requested', $output);
    }

    public function test_request_sudo_is_a_no_op_when_already_approved(): void
    {
        $task = Task::factory()->create();
        $task->requestSudo('earlier');
        $task->approveSudo();

        $tool = new TaskEngineTool($this->app);

        $output = $tool->execute(['action' => 'request_sudo', 'task_id' => $task->id]);

        $this->assertStringContainsString('already has sudo approved', $output);
    }

    public function test_request_sudo_requires_a_task_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task_id is required');

        (new TaskEngineTool($this->app))->execute(['action' => 'request_sudo']);
    }

    public function test_shell_tool_blocks_sudo_when_not_approved(): void
    {
        $task = Task::factory()->create();
        $this->app->make(ExecutionScope::class)->beginTask($task);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Elevated (sudo) privileges are not approved');

        (new ShellTool)->execute(['command' => 'sudo whoami']);
    }

    public function test_shell_tool_allows_sudo_once_the_task_is_approved(): void
    {
        $task = Task::factory()->create();
        $task->requestSudo('approved');
        $task->approveSudo();

        $scope = $this->app->make(ExecutionScope::class);
        $scope->beginTask($task);

        $output = (new ShellTool)->execute(['command' => 'echo sudo-approved']);

        $this->assertStringContainsString('sudo-approved', $output);

        $scope->end();
    }

    public function test_shell_tool_allows_sudo_when_the_mission_is_approved(): void
    {
        $mission = Mission::factory()->create();
        $mission->requestSudo('approved');
        $mission->approveSudo();

        $scope = $this->app->make(ExecutionScope::class);
        $scope->beginMission($mission);

        $output = (new ShellTool)->execute(['command' => 'echo sudo-approved']);

        $this->assertStringContainsString('sudo-approved', $output);

        $scope->end();
    }

    public function test_approving_sudo_resumes_a_parked_task(): void
    {
        $user = User::factory()->create();

        $task = Task::factory()->create(['status' => TaskStatus::Running]);
        $task->requestSudo('Install package');

        // Park it the way the engine would.
        app(TaskEngine::class)->park($task, 'Awaiting sudo approval', TaskFailureType::PermissionError);

        $this->actingAs($user)
            ->post(route('agent-tasks.sudo.approve', $task))
            ->assertRedirect();

        $task->refresh();

        $this->assertSame(SudoStatus::Approved, $task->sudo_status);
        $this->assertSame($user->id, $task->sudo_resolved_by);
        $this->assertSame(TaskStatus::Running, $task->status);
        $this->assertFalse((bool) $task->needs_human_input);

        Queue::assertPushed(ExecuteTaskStepJob::class, fn (ExecuteTaskStepJob $job): bool => $job->taskId === $task->id);
    }

    public function test_denying_sudo_cancels_a_waiting_task(): void
    {
        $user = User::factory()->create();

        $task = Task::factory()->create(['status' => TaskStatus::Running]);
        $task->requestSudo('Install package');
        app(TaskEngine::class)->park($task, 'Awaiting sudo approval', TaskFailureType::PermissionError);

        $this->actingAs($user)
            ->post(route('agent-tasks.sudo.deny', $task))
            ->assertRedirect();

        $task->refresh();

        $this->assertSame(SudoStatus::Denied, $task->sudo_status);
        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertSame($user->id, $task->sudo_resolved_by);
    }

    public function test_mission_sudo_can_be_approved_and_denied(): void
    {
        $user = User::factory()->create();
        $mission = Mission::factory()->create();
        $mission->requestSudo('Deploy needs root');

        $this->actingAs($user)
            ->post(route('missions.sudo.approve', $mission))
            ->assertRedirect();

        $this->assertSame(SudoStatus::Approved, $mission->fresh()->sudo_status);

        $other = Mission::factory()->create();
        $other->requestSudo('Deploy needs root');

        $this->actingAs($user)
            ->post(route('missions.sudo.deny', $other))
            ->assertRedirect();

        $this->assertSame(SudoStatus::Denied, $other->fresh()->sudo_status);
    }
}
