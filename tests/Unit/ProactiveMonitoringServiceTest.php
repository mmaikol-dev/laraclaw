<?php

namespace Tests\Unit;

use App\Models\AgentMemory;
use App\Services\Agent\ProactiveMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProactiveMonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_environment_awareness_as_operational_memory(): void
    {
        config()->set('agent.working_dir', base_path());
        config()->set('agent.allowed_paths', base_path().','.'/tmp/laraclaw');

        $service = new ProactiveMonitoringService;
        $environment = $service->refreshEnvironmentAwareness();

        $this->assertSame(base_path(), $environment['working_dir']);
        $this->assertContains('Laravel', $environment['stack']);
        $this->assertTrue($environment['git_repository']);

        $this->assertDatabaseHas('agent_memories', [
            'key' => 'environment.working_dir',
            'category' => 'environment',
            'scope' => 'environment',
        ]);

        $this->assertSame(
            base_path(),
            AgentMemory::query()->where('key', 'environment.working_dir')->value('value'),
        );
    }
}
