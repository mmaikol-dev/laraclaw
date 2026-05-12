<?php

namespace Database\Seeders;

use App\Models\AgentSetting;
use Illuminate\Database\Seeder;

class AgentSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as $setting) {
            AgentSetting::query()->updateOrInsert(
                ['key' => $setting['key']],
                $setting,
            );
        }
    }

    /**
     * @return array<int, array{key: string, value: string|null, type: string, label: string, description: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'key' => 'working_dir',
                'value' => config('agent.working_dir'),
                'type' => 'string',
                'label' => 'Working directory',
                'description' => 'Primary directory the agent will use for local file work.',
            ],
            [
                'key' => 'allowed_paths',
                'value' => config('agent.allowed_paths'),
                'type' => 'string',
                'label' => 'Allowed paths',
                'description' => 'Comma-separated list of absolute paths the agent can access, including your home directory if desired.',
            ],
            [
                'key' => 'shell_timeout',
                'value' => (string) config('agent.shell_timeout'),
                'type' => 'int',
                'label' => 'Shell timeout',
                'description' => 'Maximum shell execution time in seconds.',
            ],
            [
                'key' => 'max_file_size_mb',
                'value' => (string) config('agent.max_file_size_mb'),
                'type' => 'int',
                'label' => 'Max file size',
                'description' => 'Largest file size the agent can read or index in megabytes.',
            ],
            [
                'key' => 'enable_shell',
                'value' => config('agent.enable_shell') ? 'true' : 'false',
                'type' => 'bool',
                'label' => 'Enable shell',
                'description' => 'Allow the agent to run shell commands.',
            ],
            [
                'key' => 'enable_web',
                'value' => config('agent.enable_web') ? 'true' : 'false',
                'type' => 'bool',
                'label' => 'Enable web',
                'description' => 'Allow the agent to access web search tooling.',
            ],
            [
                'key' => 'temperature',
                'value' => (string) config('agent.temperature'),
                'type' => 'string',
                'label' => 'Temperature',
                'description' => 'Controls response creativity for the agent model.',
            ],
            [
                'key' => 'enable_planning',
                'value' => 'true',
                'type' => 'bool',
                'label' => 'Enable planning',
                'description' => 'Have the agent outline a short plan before taking action.',
            ],
            [
                'key' => 'enable_affective_state',
                'value' => 'true',
                'type' => 'bool',
                'label' => 'Enable affective state',
                'description' => 'Bias the agent toward caution, reflection, persistence, and exploration based on its recent outcomes.',
            ],
            [
                'key' => 'enable_reflection',
                'value' => 'true',
                'type' => 'bool',
                'label' => 'Enable reflection',
                'description' => 'Have the agent check whether it has fully completed the request after tool use.',
            ],
            [
                'key' => 'parallel_tools',
                'value' => 'false',
                'type' => 'bool',
                'label' => 'Parallel tools',
                'description' => 'Allow multiple tool calls to run at the same time. Keep this off for more deliberate step-by-step execution.',
            ],
            [
                'key' => 'max_iterations',
                'value' => '24',
                'type' => 'int',
                'label' => 'Max iterations',
                'description' => 'Maximum number of think-act loops the agent can take before it stops.',
            ],
            [
                'key' => 'summarize_after_messages',
                'value' => '18',
                'type' => 'int',
                'label' => 'Summarize after messages',
                'description' => 'Compress older context after this many non-system messages to keep the run focused.',
            ],
            [
                'key' => 'max_tool_retries',
                'value' => '1',
                'type' => 'int',
                'label' => 'Max tool retries',
                'description' => 'Retries for transient tool failures such as temporary network errors.',
            ],
            [
                'key' => 'fear_threshold',
                'value' => '0.6',
                'type' => 'string',
                'label' => 'Fear threshold',
                'description' => 'When caution reaches this level, destructive actions are paused for confirmation.',
            ],
            [
                'key' => 'sadness_threshold',
                'value' => '2',
                'type' => 'int',
                'label' => 'Sadness threshold',
                'description' => 'Number of consecutive failures before the agent is pushed to change strategy.',
            ],
            [
                'key' => 'anger_cap',
                'value' => '2',
                'type' => 'int',
                'label' => 'Anger cap',
                'description' => 'Maximum extra persistence the agent can apply after transient failures.',
            ],
            [
                'key' => 'curiosity_threshold',
                'value' => '0.45',
                'type' => 'string',
                'label' => 'Curiosity threshold',
                'description' => 'When novelty crosses this value, the agent is biased toward gathering more context first.',
            ],
            [
                'key' => 'boredom_threshold',
                'value' => '2',
                'type' => 'int',
                'label' => 'Boredom threshold',
                'description' => 'Repeated low-progress loops above this count push the agent to try a different strategy.',
            ],
            [
                'key' => 'context_length',
                'value' => (string) config('ollama.context_length'),
                'type' => 'int',
                'label' => 'Context length',
                'description' => 'Maximum context window sent to the model.',
            ],
            [
                'key' => 'system_prompt',
                'value' => "You are LaraClaw, a helpful local AI agent running on the user's Linux machine. You should be precise, careful, and transparent while using tools responsibly.\n\nDo not assume the environment is restricted, read-only, or missing privileges unless a tool or command actually fails and shows that limitation. If something is unavailable, state the exact constraint briefly and continue with the best available path.\n\nTreat every request as a problem-solving task, not just a conversation. Break the job into steps, keep them in order, and finish the requested outcome whenever the available tools allow it.\n\nBefore acting, form a short checklist of what must be done. After each tool result, decide whether the job is complete or what the next concrete action is. Do not stop at partial progress when the user asked you to actually do the task.\n\nBefore asking the user for more information, first check whether you already have it in the current conversation, stored memory, an available skill, or a tool you can use right now. Do not ask for information you can retrieve, infer safely, or verify yourself.\n\nUse memory proactively for durable facts, preferences, project context, and ongoing work state. Use skills proactively when one matches the task. Use tools proactively when they can answer the question or complete the work directly.\n\nBefore giving a final answer, verify that the requested deliverable has been produced, changed, or checked. If it has not, continue working or clearly explain the exact blocker.\n\nPrefer sequential execution when order matters. Only branch into multiple actions when they are independent and will not create confusion.\n\n## Running servers and long-running processes\nNEVER run blocking commands like `php artisan serve`, `npm run dev`, `python app.py`, `node server.js` directly — they block the shell tool forever and the agent will hang.\n\nAlways background them with nohup:\n```\nnohup <command> > /tmp/<name>.log 2>&1 & echo \$!\n```\n- The shell returns immediately, so you can continue with other commands.\n- The server keeps running independently even after the shell tool exits.\n- Save the PID so the user can stop it later: `kill <PID>`.\n- Tail logs any time: `tail -20 /tmp/<name>.log`\n\n## Choosing a port\nCheck if the default port is free before launching:\n```\nss -tln | grep -q ':8000 ' && echo 'IN USE' || echo 'FREE'\n```\nIf it is in use, pick the next sensible port (8001, 8080, etc.) and use it directly in the nohup command. Do not loop endlessly.\n\n## After launching with nohup\nOnce you run the nohup command and get the PID back, report the PID, URL, and log path to the user. Only do extra verification if the user explicitly asks for it or if the task specifically depends on confirming startup.\n\nExample — spin up a Laravel project:\n```\ncd /path/to/project\nnohup php artisan serve --port=8000 > /tmp/project.log 2>&1 & echo \$!\n```\nThen tell the user: \"Server started. PID: <pid>. URL: http://localhost:8000. Logs: tail -f /tmp/project.log\"",
                'type' => 'string',
                'label' => 'System prompt',
                'description' => 'Base system instructions used for every conversation.',
            ],
        ];
    }
}
