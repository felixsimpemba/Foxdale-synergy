<?php

namespace App\Http\Requests\Routing;

use App\Enums\RotationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnCallScheduleRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'team_id' => [$required, 'integer', Rule::exists('teams', 'id')],
            'name' => [$required, 'string', 'max:150'],
            'rotation_type' => [$required, Rule::enum(RotationType::class)],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
