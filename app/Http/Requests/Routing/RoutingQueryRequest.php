<?php

namespace App\Http\Requests\Routing;

use App\Enums\Severity;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inputs for "who do I report to?" and the routing simulator.
 */
class RoutingQueryRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'severity' => ['required', Rule::enum(Severity::class)],
        ];
    }
}
