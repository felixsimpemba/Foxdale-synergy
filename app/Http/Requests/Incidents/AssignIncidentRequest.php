<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignIncidentRequest extends FormRequest
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
            'assignee_id' => ['nullable', 'required_without:team_id', 'integer', Rule::exists('users', 'id')],
            'team_id' => ['nullable', 'required_without:assignee_id', 'integer', Rule::exists('teams', 'id')],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
