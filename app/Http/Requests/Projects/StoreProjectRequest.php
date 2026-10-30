<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('key'))) {
            $this->merge(['key' => Str::upper(trim($this->input('key')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:10', 'regex:/^[A-Z][A-Z0-9]{1,9}$/',
                Rule::unique('projects', 'key'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'owner_team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'lead_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'review_required' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Use 2–10 letters or digits, starting with a letter (for example SYN).',
        ];
    }
}
