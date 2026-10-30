<?php

namespace App\Http\Requests\Projects;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Status, sprint and board order change through POST /tasks/{task}/move.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $task = $this->route('task');
        $projectId = $task->project_id;
        $member = [
            'integer',
            Rule::exists('users', 'id')->where('is_active', true),
            Rule::exists('project_members', 'user_id')->where('project_id', $projectId),
        ];

        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'status' => ['prohibited'],
            'sprint_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('sprints', 'id')->where('project_id', $projectId),
            ],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('tasks', 'id')
                    ->where('project_id', $projectId)
                    ->whereNull('parent_id')
                    ->where('id', '!=', $task->id),
            ],
            'assignee_id' => ['sometimes', 'nullable', ...$member],
            'reviewer_id' => ['sometimes', 'nullable', ...$member],
            'story_points' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'is_blocked' => ['sometimes', 'boolean'],
            'blocked_reason' => [
                'sometimes', 'nullable', 'string', 'max:500',
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
            'status.prohibited' => 'Move the task on the board to change its status.',
            'blocked_reason.required' => 'Say why the task is blocked.',
        ];
    }
}
