<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSlaPolicyRequest extends FormRequest
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
            'ack_minutes' => ['required', 'integer', 'min:1'],
            'mitigate_minutes' => ['required', 'integer', 'gte:ack_minutes'],
            'resolve_minutes' => ['required', 'integer', 'gte:mitigate_minutes'],
        ];
    }
}
