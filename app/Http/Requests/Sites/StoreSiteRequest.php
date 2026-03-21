<?php

namespace App\Http\Requests\Sites;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'server_id' => ['required', 'integer', Rule::exists('servers', 'id')->where('user_id', $this->user()->id)],
            'domain' => ['required', 'string', 'max:255'],
            'php_version' => ['required', 'string', 'in:8.1,8.2,8.3,8.4'],
            'wp_admin_user' => ['required', 'string', 'max:60'],
            'wp_admin_password' => ['required', 'string', 'min:8'],
            'wp_admin_email' => ['required', 'email', 'max:255'],
            'wp_admin_display_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'server_id.required' => 'Please select a server.',
            'server_id.exists' => 'The selected server does not exist or does not belong to you.',
            'domain.required' => 'Enter a domain name for the site.',
            'php_version.in' => 'The selected PHP version is not supported.',
        ];
    }
}
