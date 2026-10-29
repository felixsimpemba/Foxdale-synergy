<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ServiceCriticality;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150', Rule::unique('services', 'name')],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner_team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'criticality' => ['required', Rule::enum(ServiceCriticality::class)],
            'runbook_url' => ['nullable', 'url:http,https', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
