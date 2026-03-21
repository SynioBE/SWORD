<?php

namespace App\Http\Requests\Cloudflare;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDnsRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:A,CNAME,both'],
            'content' => ['required', 'string', 'max:255'],
            'cname_content' => ['nullable', 'string', 'max:255', 'required_if:type,both'],
            'proxied' => ['boolean'],
            'ttl' => ['integer', 'min:1'],
        ];
    }
}
