<?php

namespace App\Http\Requests\Auth;

use App\Enums\TokenAbility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTokenRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', 'not_regex:/^session:/'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::in(TokenAbility::personalTokenValues())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.config('incidents.auth.personal_token_max_days')],
        ];
    }
}
