<?php

namespace App\Http\Requests;

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
            'ip_address' => ['required', 'ip'],
            'provider' => ['nullable', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give your server a name.',
            'timezone.timezone' => 'Please select a valid timezone.',
            'ssh_port.min' => 'SSH port must be between 1 and 65535.',
            'ssh_port.max' => 'SSH port must be between 1 and 65535.',
        ];
    }
}
