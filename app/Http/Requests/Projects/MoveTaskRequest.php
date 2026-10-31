<?php

namespace App\Http\Requests\Projects;

use App\Enums\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * before_id and after_id are the cards the moved task lands between in the target
     * column. The server works out the position, so a filtered board cannot corrupt order.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $task = $this->route('task');
        $projectId = $task->project_id;

        return [
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'sprint_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('sprints', 'id')->where('project_id', $projectId),
            ],
            'before_id' => [
                'nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $projectId)->whereNot('id', $task->id),
            ],
            'after_id' => [
                'nullable', 'integer',
                Rule::exists('tasks', 'id')->where('project_id', $projectId)->whereNot('id', $task->id),
            ],
            'reviewer_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::exists('project_members', 'user_id')->where('project_id', $projectId),
                Rule::requiredIf(fn (): bool => $this->input('status') === TaskStatus::InReview->value
                    && $task->reviewer_id === null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reviewer_id.exists' => 'Choose an active member of this project.',
            'reviewer_id.required' => 'Choose a reviewer before moving the task to review.',
        ];
    }
}
