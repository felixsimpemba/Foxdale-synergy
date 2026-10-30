<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit the details of an incident. Severity and status have their own endpoints.
 */
class UpdateIncidentRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'impact' => ['nullable', 'string', 'max:5000'],
            'service_id' => ['sometimes', 'integer', Rule::exists('services', 'id')->where('is_active', true)],
            'category_id' => ['sometimes', 'integer', Rule::exists('categories', 'id')],
        ];
    }
}
