<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'regex:/^\\+[1-9]\\d{6,14}$/'],
            'mattermost_username' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', 'timezone:all'],

            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::exists('roles', 'name')],
            'team_ids' => ['nullable', 'array'],
            'team_ids.*' => ['integer', 'distinct', Rule::exists('teams', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be in E.164 format, for example +260971234567.',
            'email.unique' => 'This email is already in use. If it belongs to an archived account, restore that account instead.',
        ];
    }
}
