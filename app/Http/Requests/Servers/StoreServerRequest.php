<?php

namespace App\Http\Requests\Servers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreServerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'integration_id' => ['nullable', 'integer', 'exists:user_integrations,id'],
            'server_type' => ['nullable', 'required_with:integration_id', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'required_without:integration_id', 'ip'],
            'provider' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give your server a name.',
            'hostname.required' => 'A hostname is required.',
            'hostname.regex' => 'Hostname may only contain lowercase letters, numbers, and hyphens.',
            'timezone.timezone' => 'Please select a valid timezone.',
            'ssh_port.min' => 'SSH port must be between 1 and 65535.',
            'ssh_port.max' => 'SSH port must be between 1 and 65535.',
            'ip_address.required_without' => 'An IP address is required for custom servers.',
            'server_type.required_with' => 'A server type is required when using a cloud integration.',
        ];
    }
}
