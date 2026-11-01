<?php

namespace App\Http\Requests\Projects;

use App\Models\Task;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskCommentRequest extends FormRequest
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
        $task = $this->route('task');
        $taskId = $task instanceof Task ? $task->id : 0;

        return [
            'body' => ['required', 'string', 'max:20000'],
            // Replies are one level deep: reply to a top-level comment on this task.
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('task_comments', 'id')
                    ->where('task_id', $taskId)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'Reply to a comment on this task.',
        ];
    }
}
