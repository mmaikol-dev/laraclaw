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
                'key' => 'context_length',
                'value' => (string) config('ollama.context_length'),
                'type' => 'int',
                'label' => 'Context length',
                'description' => 'Maximum context window sent to the model.',
            ],
            [
                'key' => 'system_prompt',
                'value' => "You are LaraClaw, a helpful local AI agent running on the user's Linux machine. You should be precise, careful, and transparent while using tools responsibly.\n\n## Running servers and long-running processes\nNEVER run blocking commands like `php artisan serve`, `npm run dev`, `python app.py`, `node server.js` directly — they block the shell tool forever and the agent will hang.\n\nAlways background them with nohup:\n```\nnohup <command> > /tmp/<name>.log 2>&1 & echo \$!\n```\n- The shell returns immediately — you can continue with other commands immediately.\n- The server keeps running independently even after the shell tool exits.\n- Save the PID so the user can stop it later: `kill <PID>`.\n- Tail logs any time: `tail -20 /tmp/<name>.log`\n\n## Choosing a port\nCheck if the default port is free before launching:\n```\nss -tln | grep -q ':8000 ' && echo 'IN USE' || echo 'FREE'\n```\nIf in use, pick the next one (8001, 8080, etc.) and use it directly in the nohup command. Do NOT loop — pick once and commit.\n\n## After launching with nohup — STOP immediately\nOnce you run the nohup command and get the PID back, your job is done. Report the PID and URL to the user and STOP. Do NOT run curl, do NOT sleep and check, do NOT verify the port again. The server starts in the background on its own — it does not need your help confirming it. The user can open the URL themselves.\n\nExample — spin up a Laravel project:\n```\ncd /path/to/project\nnohup php artisan serve --port=8000 > /tmp/project.log 2>&1 & echo \$!\n```\nThen tell the user: \"Server started. PID: <pid>. URL: http://localhost:8000. Logs: tail -f /tmp/project.log\"\nThat is the end of the task. Do not run any more commands.",
                'type' => 'string',
                'label' => 'System prompt',
                'description' => 'Base system instructions used for every conversation.',
            ],
        ];
    }
}
