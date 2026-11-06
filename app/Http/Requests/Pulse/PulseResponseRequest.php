<?php

namespace App\Http\Requests\Pulse;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shared rules for creating and editing a Department Pulse response. The option lists
 * come from config/pulse.php, the same file the public form fetches, so a value the form
 * offers is always one the API accepts.
 */
abstract class PulseResponseRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $departments = array_keys(config('pulse.departments'));
        $options = config('pulse.options');
        $itHearOptions = array_values(array_unique([...$options['itHear'], ...$options['itHearIT']]));

        return [
            'department' => ['required', 'string', Rule::in($departments)],
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'string', 'max:120'],
            'teamSize' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'shift' => ['nullable', 'string', Rule::in($options['shift'])],
            'markets' => ['array'],
            'markets.*' => ['string', 'distinct', Rule::in($options['markets'])],
            'duties' => ['nullable', 'string', 'max:5000'],
            'tools' => ['array'],
            'tools.*' => ['string', 'distinct', Rule::in($options['tools'])],
            'toolsOther' => ['nullable', 'string', 'max:1000'],
            'problems' => ['required', 'array', 'min:1', 'max:'.config('pulse.limits.problems')],
            'problems.*.text' => ['nullable', 'string', 'max:5000'],
            'problems.*.freq' => ['nullable', 'string', Rule::in(config('pulse.frequency'))],
            'problems.*.impact' => ['nullable', 'string', Rule::in(config('pulse.impact'))],
            'problems.*.handling' => ['nullable', 'string', 'max:5000'],
            'problems.*.needs' => ['nullable', 'string', Rule::in($departments)],
            'reportsTo' => ['nullable', 'string', 'max:255'],
            'directReports' => ['nullable', 'string', 'max:255'],
            'escalation' => ['nullable', 'string', 'max:10000'],
            'cadence' => ['nullable', 'string', Rule::in($options['cadence'])],
            'escList' => ['nullable', 'string', Rule::in($options['escList'])],
            'reportChannels' => ['array'],
            'reportChannels.*' => ['string', 'distinct', Rule::in($options['reportChannels'])],
            'itHear' => ['array'],
            'itHear.*' => ['string', 'distinct', Rule::in($itHearOptions)],
            'notice' => ['nullable', 'string', Rule::in($options['notice'])],
            'itSurprise' => ['nullable', 'string', 'max:10000'],
            'itNeeds' => ['array'],
            'itNeeds.*' => ['string', 'distinct', Rule::in($options['itNeeds'])],
            'watchFor' => ['nullable', 'string', 'max:10000'],
            'ratings' => ['array'],
            'ratings.*' => ['integer', 'min:1', 'max:5'],
            'dependsOn' => ['nullable', 'string', Rule::in($departments)],
            'dependsOnUs' => ['nullable', 'string', Rule::in($departments)],
            'gap' => ['nullable', 'string', 'max:10000'],
            'helps' => ['array', 'max:'.config('pulse.limits.helps')],
            'helps.*' => ['string', 'distinct', Rule::in($options['helps'])],
            'oneThing' => ['nullable', 'string', 'max:10000'],
            'anything' => ['nullable', 'string', 'max:10000'],
            // Honeypot. A person never sees or fills this field.
            'website' => ['prohibited'],
        ];
    }

    /**
     * Rules that span several fields: at least one problem needs a description, and a
     * response may only rate the departments it does not belong to.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $department = (string) $this->input('department');
                $departments = array_keys(config('pulse.departments'));
                $problems = $this->input('problems', []);
                $problems = is_array($problems) ? $problems : [];

                $described = array_filter(
                    $problems,
                    fn (mixed $problem): bool => is_array($problem) && trim((string) ($problem['text'] ?? '')) !== '',
                );

                if ($described === []) {
                    $validator->errors()->add('problems', 'Describe at least one problem.');
                }

                foreach ((array) $this->input('ratings', []) as $id => $value) {
                    if (! is_string($id) || ! in_array($id, $departments, true) || $id === $department) {
                        $validator->errors()->add('ratings', 'Rate only the other departments.');
                        break;
                    }
                }

                foreach (['dependsOn', 'dependsOnUs'] as $field) {
                    if ($this->input($field) !== null && $this->input($field) === $department) {
                        $validator->errors()->add($field, 'Choose another department.');
                    }
                }

                foreach ($problems as $index => $problem) {
                    if (is_array($problem) && ($problem['needs'] ?? null) === $department) {
                        $validator->errors()->add("problems.{$index}.needs", 'Choose another department.');
                    }
                }
            },
        ];
    }

    /**
     * The JSON document stored in pulse_responses.answers, with identity fields removed and
     * the empty problem blocks the form leaves behind filtered out.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $data = $this->validated();
        unset($data['department'], $data['name'], $data['role'], $data['website']);

        $data['problems'] = array_values(array_filter(
            $data['problems'] ?? [],
            fn (mixed $problem): bool => is_array($problem) && trim((string) ($problem['text'] ?? '')) !== '',
        ));

        $allowedRatings = array_flip($this->otherDepartmentIds());
        $data['ratings'] = array_intersect_key($data['ratings'] ?? [], $allowedRatings);
        $data['ratings'] = array_map(fn (mixed $value): int => (int) $value, $data['ratings']);

        return $data;
    }

    /**
     * @return list<string>
     */
    protected function otherDepartmentIds(): array
    {
        return array_values(array_diff(
            array_keys(config('pulse.departments')),
            [(string) $this->input('department')],
        ));
    }
}
