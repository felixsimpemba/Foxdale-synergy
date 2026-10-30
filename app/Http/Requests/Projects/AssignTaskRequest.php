<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignTaskRequest extends FormRequest
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
        $projectId = $this->route('task')->project_id;

        return [
            // present (not required) so null unassigns the task.
            'assignee_id' => [
                'present', 'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::exists('project_members', 'user_id')->where('project_id', $projectId),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'Choose an active member of this project.',
        ];
    }
}
