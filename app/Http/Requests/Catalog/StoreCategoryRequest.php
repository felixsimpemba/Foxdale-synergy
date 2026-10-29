<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('parent_id', $this->input('parent_id'))],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ];
    }
}
