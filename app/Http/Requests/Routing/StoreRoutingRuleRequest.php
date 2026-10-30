<?php

namespace App\Http\Requests\Routing;

use App\Enums\AssignmentMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Empty category, service and severity bounds match anything; an empty team routes to the
 * service's owner team.
 */
class StoreRoutingRuleRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:150'],
            'priority' => [$required, 'integer', 'min:1', 'max:9999'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'min_severity' => ['nullable', 'integer', 'between:1,4'],
            'max_severity' => ['nullable', 'integer', 'between:1,4', 'gte:min_severity'],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'escalation_policy_id' => ['nullable', 'integer', Rule::exists('escalation_policies', 'id')],
            'assignment_mode' => [$required, Rule::enum(AssignmentMode::class)],
            'notify_channel_ids' => ['nullable', 'array'],
            'notify_channel_ids.*' => ['integer', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
