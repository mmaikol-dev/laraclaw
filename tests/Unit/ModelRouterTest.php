<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\TaskEngine\ModelRouter;
use App\Services\TaskEngine\SpecialistProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_model_comes_from_config(): void
    {
        config(['ollama.agent_model' => 'glm-5:cloud']);

        $router = new ModelRouter;

        $this->assertSame('glm-5:cloud', $router->defaultModel());
    }

    public function test_models_are_routed_by_complexity_level(): void
    {
        config([
            'agent.models' => [
                'simple' => 'tiny:v1',
                'coding' => 'coder:v1',
                'reasoning' => 'thinker:v1',
                'review' => 'inspector:v1',
                'complex' => 'heavy:v1',
            ],
        ]);

        $router = new ModelRouter;

        $this->assertSame('tiny:v1', $router->modelForComplexity('simple'));
        $this->assertSame('coder:v1', $router->modelForComplexity('coding'));
        $this->assertSame('heavy:v1', $router->modelForComplexity('complex'));
        $this->assertSame($router->defaultModel(), $router->modelForComplexity('unknown'));
    }

    public function test_complexity_classification_of_long_goals(): void
    {
        $router = new ModelRouter;

        $complexGoal = implode(' ', array_fill(0, 50, 'word'));

        $this->assertSame('coding', $router->classifyComplexity('fix the login bug'));
        $this->assertSame('complex', $router->classifyComplexity(
            'Implement a complete architecture overhaul: '.$complexGoal
        ));
        $this->assertSame('simple', $router->classifyComplexity('what is the weather today'));
    }

    public function test_model_for_task_uses_complexity_level_when_set(): void
    {
        config(['agent.models.coding' => 'coder:v1']);

        $task = Task::factory()->create([
            'goal' => 'fix the login bug',
            'complexity_level' => 'coding',
        ]);

        $router = new ModelRouter;

        $this->assertSame('coder:v1', $router->modelForTask($task));
    }

    public function test_model_for_specialist_uses_its_tier(): void
    {
        config(['agent.models' => [
            'coding' => 'coder:v1',
            'reasoning' => 'thinker:v1',
        ]]);

        $router = new ModelRouter;

        $coder = new SpecialistProfile('coder', 'Coder', 'code', 'coding', []);
        $planner = new SpecialistProfile('planner', 'Planner', 'plan', 'reasoning', []);

        $this->assertSame('coder:v1', $router->modelForSpecialist($coder));
        $this->assertSame('thinker:v1', $router->modelForSpecialist($planner));
    }

    public function test_failure_escalation_moves_to_a_stronger_tier(): void
    {
        config([
            'agent.models' => [
                'simple' => 'simple:v1',
                'coding' => 'coding:v1',
                'reasoning' => 'reasoning:v1',
                'review' => 'review:v1',
                'complex' => 'complex:v1',
            ],
            'ollama.agent_model' => 'default:v1',
        ]);

        $task = Task::factory()->create([
            'goal' => 'fix the login bug',
            'model' => 'simple:v1',
        ]);

        $router = new ModelRouter;
        $result = $router->escalateForFailure($task, 'Retried 3 times');

        $this->assertTrue($result['escalated']);
        $this->assertSame('review:v1', $result['model']);
        $this->assertSame('Retried 3 times', $result['reason']);
    }
}
