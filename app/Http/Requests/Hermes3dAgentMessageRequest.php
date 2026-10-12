<?php

namespace App\Http\Requests;

use App\Models\AgentMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class Hermes3dAgentMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:4000'],
            'from' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'string', Rule::in(AgentMessage::KINDS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'A message body is required.',
        ];
    }
}
