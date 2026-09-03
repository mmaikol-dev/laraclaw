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
        $workingDir = sys_get_temp_dir().'/laraclaw-monitoring-'.uniqid();
        @mkdir($workingDir.'/app', 0777, true);
        @mkdir($workingDir.'/.git');
        file_put_contents($workingDir.'/composer.json', (string) file_get_contents(base_path('composer.json')));

        config()->set('agent.working_dir', $workingDir);
        config()->set('agent.allowed_paths', $workingDir.','.'/tmp/laraclaw');

        try {
            $service = new ProactiveMonitoringService;
            $environment = $service->refreshEnvironmentAwareness();

            $this->assertSame($workingDir, $environment['working_dir']);
            $this->assertContains('Laravel', $environment['stack']);
            $this->assertTrue($environment['git_repository']);

            $this->assertDatabaseHas('agent_memories', [
                'key' => 'environment.working_dir',
                'category' => 'environment',
                'scope' => 'environment',
            ]);

            $this->assertSame(
                $workingDir,
                AgentMemory::query()->where('key', 'environment.working_dir')->value('value'),
            );
        } finally {
            @unlink($workingDir.'/composer.json');
            @rmdir($workingDir.'/app');
            @rmdir($workingDir.'/.git');
            @rmdir($workingDir);
        }
    }
}
