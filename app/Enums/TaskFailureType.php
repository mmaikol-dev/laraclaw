<?php

namespace App\Enums;

enum TaskFailureType: string
{
    case Transient = 'transient';
    case ToolError = 'tool_error';
    case NetworkError = 'network_error';
    case ModelError = 'model_error';
    case ValidationError = 'validation_error';
    case LogicalError = 'logical_error';
    case PermissionError = 'permission_error';
    case Unrecoverable = 'unrecoverable';

    public function isRetryable(): bool
    {
        return in_array($this, [
            self::Transient,
            self::ToolError,
            self::NetworkError,
            self::ModelError,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Transient => 'Transient',
            self::ToolError => 'Tool Error',
            self::NetworkError => 'Network Error',
            self::ModelError => 'Model Error',
            self::ValidationError => 'Validation Error',
            self::LogicalError => 'Logical Error',
            self::PermissionError => 'Permission Error',
            self::Unrecoverable => 'Unrecoverable',
        };
    }
}
