<?php

namespace Tests\Unit;

use App\Enums\TaskFailureType;
use App\Services\TaskEngine\FailureClassifier;
use Tests\TestCase;

class FailureClassifierTest extends TestCase
{
    public function test_network_errors_are_retryable(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(
            TaskFailureType::NetworkError,
            $classifier->classify('Connection refused while reaching the API.')
        );
        $this->assertTrue(TaskFailureType::NetworkError->isRetryable());
    }

    public function test_permission_errors_are_not_retryable(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(
            TaskFailureType::PermissionError,
            $classifier->classify('Permission denied writing to /etc/hosts')
        );
        $this->assertFalse(TaskFailureType::PermissionError->isRetryable());
    }

    public function test_model_errors_are_transient(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(
            TaskFailureType::ModelError,
            $classifier->classify('Ollama model glm-5:cloud is overloaded')
        );
        $this->assertTrue(TaskFailureType::ModelError->isRetryable());
    }

    public function test_validation_errors_are_not_retryable(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(
            TaskFailureType::ValidationError,
            $classifier->classify('File not found: src/missing.php')
        );
    }

    public function test_empty_message_is_transient(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(TaskFailureType::Transient, $classifier->classify(''));
    }

    public function test_tool_context_produces_tool_error(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(
            TaskFailureType::ToolError,
            $classifier->classify('Something unexpected happened', 'file')
        );
    }

    public function test_generic_failure_is_logical_error(): void
    {
        $classifier = new FailureClassifier;

        $this->assertSame(TaskFailureType::LogicalError, $classifier->classify('The moon is made of cheese'));
    }
}
