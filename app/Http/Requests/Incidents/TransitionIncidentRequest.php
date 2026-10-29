<?php

namespace App\Http\Requests\Incidents;

use App\Enums\FixType;
use App\Enums\IncidentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Notes are required when mitigating, reopening and cancelling; a resolution (or a saved one) when resolving.
 */
class TransitionIncidentRequest extends FormRequest
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
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'array'],

            'resolution.summary' => ['required_with:resolution', 'string', 'max:5000'],
            'resolution.fix_type' => ['required_with:resolution', Rule::enum(FixType::class)],
            'resolution.workaround' => ['nullable', 'string', 'max:5000'],
            'resolution.permanent_fix' => ['nullable', 'string', 'max:5000'],

            'duplicate_of_id' => ['nullable', 'integer', Rule::exists('incidents', 'id')->whereNull('deleted_at')],
        ];
    }
}
