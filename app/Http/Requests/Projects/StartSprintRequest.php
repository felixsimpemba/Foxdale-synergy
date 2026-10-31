<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dates are optional; the sprint keeps its planned dates or starts today.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'starts_on' => ['nullable', 'date'],
            'ends_on' => [
                'nullable', 'date',
                Rule::when($this->filled('starts_on'), ['after_or_equal:starts_on']),
            ],
        ];
    }
}
