<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteSprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "backlog" (the default) or a sprint id. The service checks that the target belongs
     * to the same project and is not completed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'move_unfinished_to' => ['nullable', 'string', 'regex:/^(backlog|\d+)$/'],
        ];
    }
}
