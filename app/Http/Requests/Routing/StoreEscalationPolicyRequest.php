<?php

namespace App\Http\Requests\Routing;

use App\Enums\EscalationTargetType;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

/**
 * Levels are numbered from 1 and their after_minutes must increase with the level. user and
 * role targets need a target_id; for on_call, team_lead and team it is an optional team
 * (empty = the incident's team). On update, levels (when sent) replace the existing ones.
 */
class StoreEscalationPolicyRequest extends FormRequest
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
        $creating = $this->isMethod('POST');
        $policy = $this->route('escalationPolicy');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150', Rule::unique('escalation_policies', 'name')->ignore($policy)],
            'description' => ['nullable', 'string', 'max:1000'],
            'levels' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'levels.*.level' => ['required', 'integer', 'between:1,10', 'distinct'],
            'levels.*.after_minutes' => ['required', 'integer', 'between:0,10080'],
            'levels.*.target_type' => ['required', Rule::enum(EscalationTargetType::class)],
            'levels.*.target_id' => ['nullable', 'integer'],
            'levels.*.max_severity' => ['nullable', 'integer', 'between:1,4'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $levels = $this->collect('levels')->sortBy('level')->values();
            $previous = null;

            foreach ($levels as $index => $level) {
                if ($previous !== null && $level['after_minutes'] <= $previous) {
                    $validator->errors()->add('levels', 'Each level must start later than the level before it.');

                    return;
                }

                $previous = $level['after_minutes'];
                $this->checkTarget($validator, $index, EscalationTargetType::from($level['target_type']), $level['target_id'] ?? null);
            }
        }];
    }

    private function checkTarget(Validator $validator, int $index, EscalationTargetType $type, ?int $targetId): void
    {
        $field = "levels.{$index}.target_id";

        if ($targetId === null) {
            if ($type->needsTargetId()) {
                $validator->errors()->add($field, "A {$type->value} target needs a target_id.");
            }

            return;
        }

        $exists = match ($type) {
            EscalationTargetType::User => User::whereKey($targetId)->exists(),
            EscalationTargetType::Role => Role::whereKey($targetId)->exists(),
            default => Team::whereKey($targetId)->exists(),
        };

        if (! $exists) {
            $validator->errors()->add($field, 'The selected target does not exist.');
        }
    }
}
