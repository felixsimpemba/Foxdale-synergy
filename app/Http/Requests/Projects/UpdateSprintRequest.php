<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSprintRequest extends FormRequest
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
        $sprint = $this->route('sprint');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('sprints', 'name')
                    ->where('project_id', $sprint->project_id)
                    ->ignore($sprint),
            ],
            'goal' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
