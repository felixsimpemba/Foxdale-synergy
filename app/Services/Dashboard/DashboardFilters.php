<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;

/**
 * The period and scope every dashboard query uses. from/to are whole days in the viewer's
 * timezone, stored as UTC instants; buckets for trend charts use the same timezone.
 */
final readonly class DashboardFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
        public ?int $teamId = null,
        public ?int $serviceId = null,
    ) {}

    /**
     * @param  array{from?: string|null, to?: string|null, team_id?: int|string|null, service_id?: int|string|null}  $input
     */
    public static function fromInput(array $input, string $timezone, int $defaultDays = 30): self
    {
        $to = filled($input['to'] ?? null)
            ? CarbonImmutable::parse($input['to'], $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->endOfDay();

        $from = filled($input['from'] ?? null)
            ? CarbonImmutable::parse($input['from'], $timezone)->startOfDay()
            : $to->subDays($defaultDays - 1)->startOfDay();

        return new self(
            $from->utc(),
            $to->utc(),
            $timezone,
            filled($input['team_id'] ?? null) ? (int) $input['team_id'] : null,
            filled($input['service_id'] ?? null) ? (int) $input['service_id'] : null,
        );
    }

    /**
     * The window of the same length that ends just before this one, for "vs previous period".
     */
    public function previous(): self
    {
        $seconds = (int) $this->from->diffInSeconds($this->to) + 1;

        return new self($this->from->subSeconds($seconds), $this->from->subSecond(), $this->timezone, $this->teamId, $this->serviceId);
    }

    /**
     * @return array{from: string, to: string}
     */
    public function period(): array
    {
        return [
            'from' => $this->from->setTimezone($this->timezone)->toDateString(),
            'to' => $this->to->setTimezone($this->timezone)->toDateString(),
        ];
    }

    public function cacheKey(): string
    {
        return implode(':', [$this->from->timestamp, $this->to->timestamp, $this->timezone, $this->teamId ?? '-', $this->serviceId ?? '-']);
    }
}
