<?php

namespace App\Http\Requests\Dashboard;

use App\Enums\DashboardDimension;
use App\Enums\DashboardInterval;
use App\Enums\DashboardMetric;
use App\Enums\PermissionKey;
use App\Services\Dashboard\DashboardFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters shared by every dashboard endpoint: from and to (whole days, at most a year apart),
 * team_id and service_id. Trends also take metric and interval; breakdowns take by.
 */
class DashboardRequest extends FormRequest
{
    private const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()->can(PermissionKey::DashboardView->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')],
            'metric' => [Rule::requiredIf($this->routeIs('*dashboard.trends')), Rule::enum(DashboardMetric::class)],
            'interval' => ['nullable', Rule::enum(DashboardInterval::class)],
            'by' => [Rule::requiredIf($this->routeIs('*dashboard.breakdown')), Rule::enum(DashboardDimension::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $filters = $this->filters();

            if ($filters->from->diffInDays($filters->to) > self::MAX_DAYS) {
                $validator->errors()->add('from', 'The period can be at most a year.');
            }
        }];
    }

    public function filters(): DashboardFilters
    {
        return DashboardFilters::fromInput(
            $this->only(['from', 'to', 'team_id', 'service_id']),
            $this->user()->timezone ?: config('app.timezone'),
        );
    }
}
