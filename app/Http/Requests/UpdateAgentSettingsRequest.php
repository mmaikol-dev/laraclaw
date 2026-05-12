<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.working_dir' => ['nullable', 'string', 'max:500'],
            'settings.allowed_paths' => ['nullable', 'string', 'max:2000'],
            'settings.max_file_size_mb' => ['nullable', 'integer', 'min:1', 'max:1024'],
            'settings.enable_shell' => ['nullable', 'boolean'],
            'settings.enable_web' => ['nullable', 'boolean'],
            'settings.enable_planning' => ['nullable', 'boolean'],
            'settings.enable_affective_state' => ['nullable', 'boolean'],
            'settings.enable_reflection' => ['nullable', 'boolean'],
            'settings.parallel_tools' => ['nullable', 'boolean'],
            'settings.shell_timeout' => ['nullable', 'integer', 'min:1', 'max:60'],
            'settings.max_iterations' => ['nullable', 'integer', 'min:1', 'max:500'],
            'settings.summarize_after_messages' => ['nullable', 'integer', 'min:0', 'max:500'],
            'settings.max_tool_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'settings.fear_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'settings.sadness_threshold' => ['nullable', 'integer', 'min:1', 'max:10'],
            'settings.anger_cap' => ['nullable', 'integer', 'min:0', 'max:10'],
            'settings.curiosity_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'settings.boredom_threshold' => ['nullable', 'integer', 'min:1', 'max:10'],
            'settings.temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'settings.context_length' => ['nullable', 'integer', 'min:256', 'max:262144'],
            'settings.system_prompt' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.required' => 'Settings payload is required.',
            'settings.array' => 'Settings must be sent as an object.',
            'settings.shell_timeout.max' => 'Shell timeout may not exceed 60 seconds.',
            'settings.temperature.max' => 'Temperature must be between 0 and 1.',
        ];
    }
}
