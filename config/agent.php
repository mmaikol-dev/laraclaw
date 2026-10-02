<?php

return [
    'working_dir' => env('AGENT_WORKING_DIR', '/tmp/laraclaw'),
    'allowed_paths' => env('AGENT_ALLOWED_PATHS', '/tmp/laraclaw,'.env('HOME', '/tmp/laraclaw')),
    'home_dir' => env('HOME', '/tmp/laraclaw'),
    'shell_timeout' => (int) env('AGENT_SHELL_TIMEOUT', 30),
    'opencode_binary' => env('AGENT_OPENCODE_BINARY', ''),
    'opencode_binary_fallback' => '.opencode/bin/opencode',
    'opencode_timeout' => (int) env('AGENT_OPENCODE_TIMEOUT', 3600),
    'mission_max_feature_attempts' => (int) env('AGENT_MISSION_MAX_ATTEMPTS', 3),
    'max_file_size_mb' => (int) env('AGENT_MAX_FILE_SIZE_MB', 10),
    'max_output_lines' => (int) env('AGENT_MAX_OUTPUT_LINES', 500),
    'enable_shell' => filter_var(env('AGENT_ENABLE_SHELL', true), FILTER_VALIDATE_BOOLEAN),
    'enable_web' => filter_var(env('AGENT_ENABLE_WEB', true), FILTER_VALIDATE_BOOLEAN),
    'temperature' => '0.7',

    // Model routing for the task engine.
    'models' => [
        'simple' => env('SIMPLE_TASK_MODEL', env('OLLAMA_AGENT_MODEL', 'glm-5:cloud')),
        'coding' => env('CODING_TASK_MODEL', env('OLLAMA_AGENT_MODEL', 'glm-5:cloud')),
        'reasoning' => env('REASONING_TASK_MODEL', env('OLLAMA_AGENT_MODEL', 'glm-5:cloud')),
        'review' => env('REVIEW_TASK_MODEL', env('OLLAMA_AGENT_MODEL', 'glm-5:cloud')),
        'complex' => env('COMPLEX_TASK_MODEL', env('OLLAMA_AGENT_MODEL', 'glm-5:cloud')),
    ],

    // Task engine tuning.
    'task' => [
        'queue' => (string) env('AGENT_TASK_QUEUE', 'tasks'),
        'heartbeat_interval_seconds' => (int) env('AGENT_TASK_HEARTBEAT_SECONDS', 90),
        'stale_grace_seconds' => (int) env('AGENT_TASK_STALE_GRACE', 120),
        'max_attempts' => (int) env('AGENT_TASK_MAX_ATTEMPTS', 5),
        'max_step_attempts' => (int) env('AGENT_TASK_MAX_STEP_ATTEMPTS', 3),
        'loop_threshold' => (int) env('AGENT_TASK_LOOP_THRESHOLD', 4),
        'max_execution_minutes' => (int) env('AGENT_TASK_MAX_EXECUTION_MINUTES', 60),
        'verification_required' => filter_var(env('AGENT_TASK_VERIFICATION_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),
        'planning_enabled' => filter_var(env('AGENT_TASK_PLANNING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],
];
