<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\TaskController;
use App\Services\Agent\OllamaService;
use PHPUnit\Framework\TestCase;

class TaskMetricsContractTest extends TestCase
{
    public function test_task_controller_exposes_expected_methods(): void
    {
        $controller = new TaskController();

        $this->assertTrue(method_exists($controller, 'index'));
        $this->assertTrue(method_exists($controller, 'show'));
        $this->assertTrue(method_exists($controller, 'stats'));
    }

    public function test_metrics_controller_accepts_ollama_service_dependency(): void
    {
        $reflection = new \ReflectionClass(MetricsController::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $parameter = $constructor?->getParameters()[0] ?? null;

        $this->assertNotNull($parameter);
        $this->assertSame(OllamaService::class, $parameter?->getType()?->getName());
    }
}
