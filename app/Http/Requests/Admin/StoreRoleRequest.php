<?php

namespace App\Http\Requests\Admin;

use App\Enums\PermissionKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionKey::values())],
        ];
    }
}
