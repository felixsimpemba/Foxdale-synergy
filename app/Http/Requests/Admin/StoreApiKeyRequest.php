<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'source' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'distinct', Rule::in(['incidents:create', 'incidents:update'])],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => ['string', 'ip'],
            'with_signing_secret' => ['sometimes', 'boolean'],
        ];
    }
}
