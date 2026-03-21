<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:api_token,global_key'],
            // Credential fields are optional on update — leave blank to keep existing values.
            'token' => ['nullable', 'string', 'max:512'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'key' => ['nullable', 'string', 'max:512'],
        ];
    }
}
