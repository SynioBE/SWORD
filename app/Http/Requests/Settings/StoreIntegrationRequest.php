<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreIntegrationRequest extends FormRequest
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
            'provider' => ['required', 'string', 'in:cloudflare,digital_ocean,hetzner'],
            'type' => ['required', 'string', 'in:api_token,global_key'],
            'token' => ['nullable', 'string', 'required_if:type,api_token', 'max:512'],
            'email' => ['nullable', 'string', 'email', 'required_if:type,global_key', 'max:255'],
            'key' => ['nullable', 'string', 'required_if:type,global_key', 'max:512'],
        ];
    }
}
