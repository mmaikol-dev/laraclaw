<?php

namespace App\Services\TaskEngine;

use App\Enums\TaskFailureType;

/**
 * Classifies tool/step failures so retries can be intelligent rather than
 * blind repetition.
 */
class FailureClassifier
{
    /**
     * @var array<int, string> network/timeout signal phrases
     */
    private const NETWORK_PATTERNS = [
        'timed out', 'timeout', 'connection refused', 'network', 'curl error',
        'could not connect', 'temporarily unavailable', 'socket', 'dns',
    ];

    /**
     * @var array<int, string> permission signal phrases
     */
    private const PERMISSION_PATTERNS = [
        'permission denied', 'not allowed', 'denied', 'forbidden', 'unauthorized',
        'access denied', 'blocked', 'no read', 'no write',
    ];

    public function classify(string $error, ?string $toolName = null): TaskFailureType
    {
        $lower = strtolower($error);

        // Empty / no message — treat as transient (likely interrupted).
        if (trim($error) === '') {
            return TaskFailureType::Transient;
        }

        foreach (self::NETWORK_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return TaskFailureType::NetworkError;
            }
        }

        foreach (self::PERMISSION_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return TaskFailureType::PermissionError;
            }
        }

        if (str_contains($lower, 'model') || str_contains($lower, 'llm') || str_contains($lower, 'ollama')) {
            return TaskFailureType::ModelError;
        }

        if (str_contains($lower, 'not found') || str_contains($lower, 'missing') || str_contains($lower, 'undefined')) {
            return TaskFailureType::ValidationError;
        }

        if ($toolName !== null) {
            return TaskFailureType::ToolError;
        }

        return TaskFailureType::LogicalError;
    }
}
