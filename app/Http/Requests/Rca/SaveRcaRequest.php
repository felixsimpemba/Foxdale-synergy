<?php

namespace App\Http\Requests\Rca;

use App\Enums\RecurrenceRisk;
use App\Enums\RootCauseCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit an RCA. Every field is optional while drafting; the approval rules are
 * checked when it is sent for review. Sending timeline replaces the whole timeline.
 */
class SaveRcaRequest extends FormRequest
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
            'summary' => ['nullable', 'string', 'max:10000'],
            'impact' => ['nullable', 'string', 'max:10000'],
            'detection' => ['nullable', 'string', 'max:10000'],
            'root_cause' => ['nullable', 'string', 'max:10000'],
            'root_cause_category' => ['nullable', Rule::enum(RootCauseCategory::class)],
            'contributing_factors' => ['nullable', 'array', 'max:30'],
            'contributing_factors.*' => ['string', 'max:1000'],
            'five_whys' => ['nullable', 'array', 'max:10'],
            'five_whys.*.why' => ['required', 'string', 'max:1000'],
            'five_whys.*.answer' => ['required', 'string', 'max:2000'],
            'went_well' => ['nullable', 'array', 'max:30'],
            'went_well.*' => ['string', 'max:1000'],
            'went_wrong' => ['nullable', 'array', 'max:30'],
            'went_wrong.*' => ['string', 'max:1000'],
            'lessons_learned' => ['nullable', 'string', 'max:10000'],
            'recurrence_risk' => ['nullable', Rule::enum(RecurrenceRisk::class)],

            'timeline' => ['nullable', 'array', 'max:200'],
            'timeline.*.at' => ['required', 'date'],
            'timeline.*.event' => ['required', 'string', 'max:2000'],
            'timeline.*.actor' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'five_whys.*.why.required' => 'Each of the five whys needs a question.',
            'five_whys.*.answer.required' => 'Each of the five whys needs an answer.',
            'timeline.*.at.required' => 'Each timeline event needs a time.',
            'timeline.*.event.required' => 'Each timeline event needs a description.',
        ];
    }

    /**
     * Blank list entries are dropped, so an empty row in the form is not an error.
     */
    protected function prepareForValidation(): void
    {
        foreach (['contributing_factors', 'went_well', 'went_wrong'] as $list) {
            if (is_array($this->input($list))) {
                $this->merge([$list => array_values(array_filter($this->input($list), fn ($entry): bool => filled($entry)))]);
            }
        }
    }
}
