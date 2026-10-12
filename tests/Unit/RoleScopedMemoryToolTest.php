<?php

namespace Tests\Unit;

use App\Models\AgentMemory;
use App\Models\AgentRoleProfile;
use App\Models\Conversation;
use App\Services\Agent\AgentIdentityService;
use App\Services\Agent\RoleProfileService;
use App\Services\Tools\MemoryTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Memory keys are namespaced per employee. The prefix is derived in code from
 * the bound role precisely so a model cannot aim a write at another namespace.
 */
class RoleScopedMemoryToolTest extends TestCase
{
    use RefreshDatabase;

    private AgentRoleProfile $qa;

    private AgentRoleProfile $devops;

    protected function setUp(): void
    {
        parent::setUp();

        app(RoleProfileService::class)->ensureDefaults();

        $this->qa = AgentRoleProfile::query()->where('slug', 'qa_operator')->firstOrFail();
        $this->devops = AgentRoleProfile::query()->where('slug', 'devops_operator')->firstOrFail();
    }

    public function test_writes_land_in_the_employees_own_namespace(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set',
            'key' => 'staging_smoke',
            'value' => 'Staging smoke tests take 14 minutes.',
        ]);

        $memory = AgentMemory::query()->sole();

        $this->assertSame('role:qa_operator:staging_smoke', $memory->key);
        $this->assertSame('role', $memory->scope);
        $this->assertSame(AgentRoleProfile::class, $memory->subject_type);
        $this->assertSame($this->qa->id, $memory->subject_id);
    }

    public function test_a_model_supplied_prefix_cannot_reach_another_namespace(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set',
            'key' => 'role:devops_operator:stolen',
            'value' => 'Attempted cross-namespace write.',
        ]);

        $memory = AgentMemory::query()->sole();

        $this->assertSame('role:qa_operator:roledevops_operatorstolen', $memory->key);
        $this->assertSame($this->qa->id, $memory->subject_id);
        $this->assertDatabaseMissing('agent_memories', ['key' => 'role:devops_operator:stolen']);
    }

    public function test_employees_cannot_read_each_others_memories(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set', 'key' => 'secret_routine', 'value' => 'QA only.']);

        $this->assertStringContainsString(
            'QA only.',
            $this->tool($this->qa)->execute(['action' => 'get', 'key' => 'role:qa_operator:secret_routine']),
            'The owner must still read its own memory.',
        );

        $this->assertStringNotContainsString(
            'QA only.',
            $this->tool($this->devops)->execute(['action' => 'get', 'key' => 'role:qa_operator:secret_routine']),
            'Another employee must not be able to read it.',
        );

        $this->assertStringNotContainsString(
            'QA only.',
            $this->tool($this->devops)->execute(['action' => 'list']),
        );
    }

    public function test_shared_scopes_stay_visible_to_every_employee(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set',
            'key' => 'user_prefers_concise',
            'value' => 'The user prefers concise answers.',
            'scope' => 'user',
        ]);

        $this->assertStringContainsString(
            'concise answers',
            $this->tool($this->devops)->execute(['action' => 'get', 'key' => 'user_prefers_concise']),
        );
        $this->assertSame('user', AgentMemory::query()->sole()->scope);
    }

    public function test_an_unbound_tool_keeps_its_previous_global_behaviour(): void
    {
        (new MemoryTool)->execute(['action' => 'set', 'key' => 'plain_key', 'value' => 'No role bound.']);

        $memory = AgentMemory::query()->sole();

        $this->assertSame('plain_key', $memory->key);
        $this->assertSame('global', $memory->scope);
    }

    public function test_own_memories_are_readable_by_their_owner(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set', 'key' => 'regression_timing', 'value' => 'Suite runs in 6 min.']);

        $found = $this->tool($this->qa)->execute(['action' => 'search', 'query' => 'regression']);

        $this->assertStringContainsString('Suite runs in 6 min.', $found);
    }

    public function test_a_role_bound_tool_cannot_delete_another_employees_memory(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set', 'key' => 'protected', 'value' => 'QA only.']);

        $result = $this->tool($this->devops)->execute(['action' => 'delete', 'key' => 'role:qa_operator:protected']);

        $this->assertStringContainsString('not found', $result);
        $this->assertDatabaseHas('agent_memories', ['key' => 'role:qa_operator:protected']);
    }

    public function test_the_employee_isolated_memory_reaches_its_own_prompt(): void
    {
        $this->tool($this->qa)->execute(['action' => 'set', 'key' => 'suite_timing', 'value' => 'The suite takes 6 minutes.']);

        $identity = app(AgentIdentityService::class);
        $context = $identity->buildPromptContext(
            Conversation::factory()->create(),
            'how long does the suite take',
            $this->qa,
        );

        $this->assertStringContainsString('The suite takes 6 minutes.', $context);
    }

    private function tool(AgentRoleProfile $role): MemoryTool
    {
        return new MemoryTool($role);
    }
}
