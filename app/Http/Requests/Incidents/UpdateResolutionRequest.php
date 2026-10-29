<?php

namespace App\Http\Requests\Incidents;

use App\Enums\FixType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResolutionRequest extends FormRequest
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
            'summary' => ['required', 'string', 'max:5000'],
            'fix_type' => ['required', Rule::enum(FixType::class)],
            'workaround' => ['nullable', 'string', 'max:5000'],
            'permanent_fix' => ['nullable', 'string', 'max:5000'],

            'steps' => ['nullable', 'array', 'max:50'],
            'steps.*' => ['string', 'max:1000'],
            'linked_prs' => ['nullable', 'array', 'max:20'],
            'linked_prs.*.url' => ['required', 'url:http,https', 'max:500'],
            'linked_prs.*.title' => ['nullable', 'string', 'max:200'],
            'deployed_at' => ['nullable', 'date'],
            'verification' => ['nullable', 'string', 'max:5000'],
            'follow_up_needed' => ['sometimes', 'boolean'],
        ];
    }
}
