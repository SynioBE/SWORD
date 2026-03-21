<?php

namespace App\Http\Requests\BackupDestinations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBackupDestinationRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:borg'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'auth_method' => ['required', 'string', 'in:password,ssh_key'],
            'password' => ['nullable', 'string'],
            'ssh_private_key' => ['nullable', 'string'],
            'storage_path' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give your backup destination a name.',
            'host.required' => 'A host address is required.',
            'username.required' => 'A username is required.',
            'storage_path.required' => 'A storage path is required.',
        ];
    }
}
