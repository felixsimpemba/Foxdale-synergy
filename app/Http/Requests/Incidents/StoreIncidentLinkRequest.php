<?php

namespace App\Http\Requests\Incidents;

use App\Enums\IncidentLinkType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentLinkRequest extends FormRequest
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
            'related_incident_id' => ['required', 'integer', Rule::exists('incidents', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::enum(IncidentLinkType::class)],
        ];
    }
}
