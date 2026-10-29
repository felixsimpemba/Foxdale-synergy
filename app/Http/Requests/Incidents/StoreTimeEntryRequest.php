<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimeEntryRequest extends FormRequest
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
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:1000'],
            'worked_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
