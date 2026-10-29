<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit the text of your own comment. Files are added or removed as documents.
 */
class UpdateIncidentUpdateRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:10000'],
        ];
    }
}
