<?php

namespace App\Http\Requests\Incidents;

use App\Enums\PermissionKey;
use App\Enums\Severity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'Choose an active user to assign it to.',
            'assignee_id.prohibited' => 'You cannot assign incidents. Leave the assignee empty and routing will choose.',
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'impact' => ['nullable', 'string', 'max:5000'],
            'service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('is_active', true)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],

            'severity' => ['required', Rule::enum(Severity::class)],
            'started_at' => ['nullable', 'date', 'before_or_equal:now'],

            // Optional: the reporter (or NOC) can pick an owner up front instead of waiting
            // for routing to fill it in. Needs incident.assign.
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::prohibitedIf(fn (): bool => ! ($this->user()?->can(PermissionKey::IncidentAssign->value) ?? false)),
            ],
        ];
    }
}
