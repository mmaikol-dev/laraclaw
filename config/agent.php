<?php

return [
    'working_dir' => env('AGENT_WORKING_DIR', '/tmp/laraclaw'),
    'allowed_paths' => env('AGENT_ALLOWED_PATHS', '/tmp/laraclaw,'.env('HOME', '/tmp/laraclaw')),
    'home_dir' => env('HOME', '/tmp/laraclaw'),
    'shell_timeout' => (int) env('AGENT_SHELL_TIMEOUT', 30),
    'max_file_size_mb' => (int) env('AGENT_MAX_FILE_SIZE_MB', 10),
    'max_output_lines' => (int) env('AGENT_MAX_OUTPUT_LINES', 500),
    'enable_shell' => filter_var(env('AGENT_ENABLE_SHELL', true), FILTER_VALIDATE_BOOLEAN),
    'enable_web' => filter_var(env('AGENT_ENABLE_WEB', true), FILTER_VALIDATE_BOOLEAN),
    'temperature' => '0.7',
];
