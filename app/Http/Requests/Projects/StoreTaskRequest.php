<?php

namespace App\Http\Requests\Projects;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
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
        $projectId = $this->route('project')->id;

        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            // Done is a result of the workflow, not a starting point.
            'status' => ['sometimes', Rule::enum(TaskStatus::class), Rule::notIn([TaskStatus::Done->value])],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'sprint_id' => [
                'nullable', 'integer',
                Rule::exists('sprints', 'id')->where('project_id', $projectId),
            ],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $projectId)->whereNull('parent_id'),
            ],
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::exists('project_members', 'user_id')->where('project_id', $projectId),
            ],
            'reviewer_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::exists('project_members', 'user_id')->where('project_id', $projectId),
                Rule::requiredIf(fn (): bool => $this->input('status') === TaskStatus::InReview->value),
            ],
            'story_points' => ['nullable', 'integer', 'min:1', 'max:100'],
            'due_date' => ['nullable', 'date'],
            'is_blocked' => ['sometimes', 'boolean'],
            'blocked_reason' => [
                'nullable', 'string', 'max:500',
                Rule::requiredIf(fn (): bool => $this->boolean('is_blocked')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'Choose an active member of this project.',
            'reviewer_id.exists' => 'Choose an active member of this project.',
            'status.not_in' => 'A new task cannot start as done.',
            'reviewer_id.required' => 'Choose a reviewer before moving the task to review.',
            'blocked_reason.required' => 'Say why the task is blocked.',
        ];
    }
}
