<?php

namespace App\Http\Requests\Documents;

use App\Enums\FixType;
use App\Enums\RootCauseCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchKnowledgeBaseRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:200'],
            'service_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'fix_type' => ['nullable', Rule::enum(FixType::class)],
            'root_cause_category' => ['nullable', Rule::enum(RootCauseCategory::class)],
            'published' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
