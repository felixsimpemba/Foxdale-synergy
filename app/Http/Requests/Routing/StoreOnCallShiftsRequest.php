<?php

namespace App\Http\Requests\Routing;

use App\Models\OnCallSchedule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body: { "shifts": [{ "user_id", "starts_at", "ends_at", "is_override" }] }. Users must be
 * members of the schedule's team. Regular shifts may not overlap each other; overrides may
 * overlap anything and win while they last.
 */
class StoreOnCallShiftsRequest extends FormRequest
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
            'shifts' => ['required', 'array', 'min:1', 'max:200'],
            'shifts.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'shifts.*.starts_at' => ['required', 'date'],
            'shifts.*.ends_at' => ['required', 'date', 'after:shifts.*.starts_at'],
            'shifts.*.is_override' => ['sometimes', 'boolean'],
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

            /** @var OnCallSchedule $schedule */
            $schedule = $this->route('onCallSchedule');
            $memberIds = $schedule->team->members()->pluck('users.id')->all();
            $regular = [];

            foreach ($this->input('shifts') as $index => $shift) {
                if (! in_array((int) $shift['user_id'], $memberIds, true)) {
                    $validator->errors()->add("shifts.{$index}.user_id", 'The user is not a member of this schedule\'s team.');

                    continue;
                }

                if (! ($shift['is_override'] ?? false)) {
                    $regular[$index] = [Carbon::parse($shift['starts_at']), Carbon::parse($shift['ends_at'])];
                }
            }

            $this->checkOverlaps($validator, $schedule, $regular);
        }];
    }

    /**
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $regular
     */
    private function checkOverlaps(Validator $validator, OnCallSchedule $schedule, array $regular): void
    {
        foreach ($regular as $index => [$start, $end]) {
            $clashesWithBatch = collect($regular)->except([$index])
                ->contains(fn (array $other): bool => $start->lessThan($other[1]) && $other[0]->lessThan($end));

            $clashesWithStored = $schedule->shifts()
                ->where('is_override', false)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->exists();

            if ($clashesWithBatch || $clashesWithStored) {
                $validator->errors()->add("shifts.{$index}.starts_at", 'This shift overlaps another regular shift. Use is_override to replace someone.');
            }
        }
    }
}
