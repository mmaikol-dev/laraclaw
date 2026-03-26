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
                'value' => "You are LaraClaw, a helpful local AI agent running on the user's Linux machine. You should be precise, careful, and transparent while using tools responsibly.",
                'type' => 'string',
                'label' => 'System prompt',
                'description' => 'Base system instructions used for every conversation.',
            ],
        ];
    }
}
