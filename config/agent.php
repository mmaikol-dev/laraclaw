<?php

$home = env('HOME', base_path());
$base = dirname($home) === '/home' ? $home : base_path();

return [
    'working_dir' => env('AGENT_WORKING_DIR', $home.'/.laraclaw/working'),
    'allowed_paths' => env('AGENT_ALLOWED_PATHS', $home.'/.laraclaw,'.$home.'/.laraclaw/working,'.$home),
    // Plain-text environment file that holds social/API credentials the tools
    // (instagram, facebook, youtube) read from when no token is passed in.
    // Keep this persistent (outside /tmp) so it survives reboots.
    'credentials_file' => env('AGENT_CREDENTIALS_FILE', $home.'/.laraclaw/.env'),
    // Root of the social-media media library. It contains CLIPPED MEDIA (raw
    // cuts) plus one folder per platform that the clip/copy tools stage into.
    'media_root' => env('AGENT_MEDIA_ROOT', $home.'/laraclaw-media'),
    // Per-clip ffmpeg execution budget, in seconds.
    'media_clip_timeout' => (int) env('AGENT_MEDIA_CLIP_TIMEOUT', 900),
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
    'enable_facebook' => filter_var(env('AGENT_ENABLE_FACEBOOK', true), FILTER_VALIDATE_BOOLEAN),
    'enable_instagram' => filter_var(env('AGENT_ENABLE_INSTAGRAM', true), FILTER_VALIDATE_BOOLEAN),
    'enable_youtube' => filter_var(env('AGENT_ENABLE_YOUTUBE', true), FILTER_VALIDATE_BOOLEAN),
    'enable_whatsapp' => filter_var(env('AGENT_ENABLE_WHATSAPP', true), FILTER_VALIDATE_BOOLEAN),
    'enable_docker' => filter_var(env('AGENT_ENABLE_DOCKER', true), FILTER_VALIDATE_BOOLEAN),
    'enable_cloudflare' => filter_var(env('AGENT_ENABLE_CLOUDFLARE', true), FILTER_VALIDATE_BOOLEAN),
    'enable_media_clip' => filter_var(env('AGENT_ENABLE_MEDIA_CLIP', true), FILTER_VALIDATE_BOOLEAN),
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
        'max_auto_recoveries' => (int) env('AGENT_TASK_MAX_AUTO_RECOVERIES', 3),
        // Maximum time (seconds) a single task step may run before it is
        // considered dead. Drives the queue job timeout, the execution lock TTL
        // and the in-flight heartbeat lease, so long agent turns (OpenCode can
        // run up to AGENT_OPENCODE_TIMEOUT seconds) are never killed mid-step or
        // falsely flagged as stalled.
        'step_timeout_seconds' => (int) env('AGENT_TASK_STEP_TIMEOUT', 3600),
        'verification_required' => filter_var(env('AGENT_TASK_VERIFICATION_REQUIRED', true), FILTER_VALIDATE_BOOLEAN),
        'planning_enabled' => filter_var(env('AGENT_TASK_PLANNING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Hermes3D office presence: how long an agent-to-agent message keeps
    // influencing the visible office state (meeting vs. working).
    'office_message_window_minutes' => (int) env('AGENT_OFFICE_MESSAGE_WINDOW_MINUTES', 10),
];
