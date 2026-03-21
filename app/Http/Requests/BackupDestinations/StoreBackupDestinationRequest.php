<?php

namespace App\Http\Requests\BackupDestinations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBackupDestinationRequest extends FormRequest
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
            'password' => ['nullable', 'required_if:auth_method,password', 'string'],
            'ssh_private_key' => ['nullable', 'required_if:auth_method,ssh_key', 'string'],
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
            'password.required_if' => 'A password is required when using password authentication.',
            'ssh_private_key.required_if' => 'An SSH private key is required when using SSH key authentication.',
            'storage_path.required' => 'A storage path is required.',
        ];
    }
}
