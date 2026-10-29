<?php

namespace App\Services\Dashboard;

use App\Enums\DashboardDimension;
use App\Enums\DashboardInterval;
use App\Enums\DashboardMetric;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RcaStatus;
use App\Enums\Severity;
use App\Enums\SlaEventType;
use App\Enums\SlaMetric;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSlaEvent;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\Service;
use App\Models\StatusHistory;
use App\Models\Team;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * KPIs for the dashboard (specification section 9). Every query starts from the incidents
 * the user may see, narrowed to the team and service filters. Durations are minutes.
 */
class DashboardService
{
    /** Share of the target elapsed at which an open incident counts as at risk. */
    private const AT_RISK_SHARE = 0.8;

    public function __construct(private DashboardCache $cache) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, DashboardFilters $filters): array
    {
        return $this->cache->remember($user, 'summary', $filters->cacheKey(), function () use ($user, $filters): array {
            $open = $this->incidents($user, $filters)
                ->whereIn('status', IncidentStatus::openValues())
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity');

            $current = $this->periodStats($user, $filters);
            $previous = $this->periodStats($user, $filters->previous());

            return [
                'period' => $filters->period(),
                'open' => [
                    'total' => (int) $open->sum(),
                    ...collect(Severity::cases())->mapWithKeys(fn (Severity $severity): array => [
                        'sev'.$severity->value => (int) ($open[$severity->value] ?? 0),
                    ])->all(),
                ],
                'unacknowledged' => $this->incidents($user, $filters)
                    ->where('status', IncidentStatus::Reported->value)
                    ->where('ack_due_at', '<', now())
                    ->count(),
                ...$current,
                'action_items' => $this->actionItemCounts($user, $filters),
                'previous' => [
                    'reported' => $previous['reported'],
                    'resolved' => $previous['resolved'],
                    'mtta_minutes' => $previous['mtta_minutes'],
                    'mttr_minutes' => $previous['mttr_minutes']['avg'],
                    'sla_compliance_pct' => $previous['sla_compliance_pct'],
                    'reopen_rate_pct' => $previous['reopen_rate_pct'],
                ],
            ];
        });
    }

    /**
     * A zero-filled time series. Counts for opened/resolved; average minutes for mtta/mttr,
     * null where nothing happened in the bucket.
     *
     * @return array{metric: string, interval: string, points: list<array{period: string, value: int|float|null, count: int}>}
     */
    public function trend(User $user, DashboardFilters $filters, DashboardMetric $metric, DashboardInterval $interval): array
    {
        return $this->cache->remember($user, "trend:{$metric->value}:{$interval->value}", $filters->cacheKey(), function () use ($user, $filters, $metric, $interval): array {
            $column = $metric->column();

            $query = $this->incidents($user, $filters)
                ->whereBetween($column, [$filters->from, $filters->to])
                ->toBase()
                ->select($column.' as at');

            if ($metric->isDuration()) {
                $query->selectRaw("TIMESTAMPDIFF(SECOND, reported_at, {$column}) as seconds");
            }

            $buckets = $this->emptyBuckets($filters, $interval);

            foreach ($query->get() as $row) {
                $key = $this->bucketKey(CarbonImmutable::parse($row->at, 'UTC'), $filters->timezone, $interval);

                if (isset($buckets[$key])) {
                    $buckets[$key][] = isset($row->seconds) ? (int) $row->seconds : 1;
                }
            }

            $points = [];

            foreach ($buckets as $period => $values) {
                $points[] = [
                    'period' => $period,
                    'value' => ! $metric->isDuration()
                        ? count($values)
                        : ($values === [] ? null : round(array_sum($values) / count($values) / 60, 1)),
                    'count' => count($values),
                ];
            }

            return ['metric' => $metric->value, 'interval' => $interval->value, 'points' => $points];
        });
    }

    /**
     * Incidents reported in the period, grouped by one dimension, largest group first.
     *
     * @return list<array{key: int|string|null, label: string, total: int, open: int, resolved: int, breached: int, mttr_minutes: float|null}>
     */
    public function breakdown(User $user, DashboardFilters $filters, DashboardDimension $by): array
    {
        return $this->cache->remember($user, "breakdown:{$by->value}", $filters->cacheKey(), function () use ($user, $filters, $by): array {
            $column = $by->column();

            $openList = implode(',', array_map(fn (string $status): string => "'{$status}'", IncidentStatus::openValues()));

            $rows = $this->incidents($user, $filters)
                ->whereBetween('reported_at', [$filters->from, $filters->to])
                ->toBase()
                ->selectRaw("{$column} as group_key, count(*) as total")
                ->selectRaw("sum(case when status in ({$openList}) then 1 else 0 end) as open_count")
                ->selectRaw('sum(case when resolved_at is not null then 1 else 0 end) as resolved_count')
                ->selectRaw('sum(case when sla_breached then 1 else 0 end) as breached_count')
                ->selectRaw('avg(case when resolved_at is not null then TIMESTAMPDIFF(SECOND, reported_at, resolved_at) end) as mttr_seconds')
                ->groupBy($column)
                ->orderByDesc('total')
                ->get();

            $labels = $this->labelsFor($by, $rows->pluck('group_key')->filter()->all());

            return $rows->map(fn (object $row): array => [
                'key' => $row->group_key === null ? null : (is_numeric($row->group_key) ? (int) $row->group_key : $row->group_key),
                'label' => $row->group_key === null ? 'None' : ($labels[$row->group_key] ?? (string) $row->group_key),
                'total' => (int) $row->total,
                'open' => (int) $row->open_count,
                'resolved' => (int) $row->resolved_count,
                'breached' => (int) $row->breached_count,
                'mttr_minutes' => $row->mttr_seconds === null ? null : round((float) $row->mttr_seconds / 60, 1),
            ])->values()->all();
        });
    }

    /**
     * Compliance by severity for the period, recent breaches, and open incidents that are past
     * 80% of a target or already over it.
     *
     * @return array<string, mixed>
     */
    public function sla(User $user, DashboardFilters $filters): array
    {
        return $this->cache->remember($user, 'sla', $filters->cacheKey(), function () use ($user, $filters): array {
            $resolved = $this->incidents($user, $filters)
                ->whereBetween('resolved_at', [$filters->from, $filters->to])
                ->whereNotNull('resolve_due_at')
                ->toBase()
                ->selectRaw('severity, count(*) as total, sum(case when resolved_at <= resolve_due_at then 1 else 0 end) as met')
                ->groupBy('severity')
                ->get()
                ->keyBy('severity');

            $acknowledged = $this->incidents($user, $filters)
                ->whereBetween('acknowledged_at', [$filters->from, $filters->to])
                ->whereNotNull('ack_due_at')
                ->toBase()
                ->selectRaw('severity, count(*) as total, sum(case when acknowledged_at <= ack_due_at then 1 else 0 end) as met')
                ->groupBy('severity')
                ->get()
                ->keyBy('severity');

            $bySeverity = collect(Severity::cases())->map(fn (Severity $severity): array => [
                'severity' => $severity->value,
                'resolved' => (int) ($resolved[$severity->value]->total ?? 0),
                'resolve_met' => (int) ($resolved[$severity->value]->met ?? 0),
                'resolve_pct' => $this->percent($resolved[$severity->value]->met ?? 0, $resolved[$severity->value]->total ?? 0),
                'acknowledged' => (int) ($acknowledged[$severity->value]->total ?? 0),
                'ack_met' => (int) ($acknowledged[$severity->value]->met ?? 0),
                'ack_pct' => $this->percent($acknowledged[$severity->value]->met ?? 0, $acknowledged[$severity->value]->total ?? 0),
            ])->all();

            $breaches = IncidentSlaEvent::query()
                ->where('type', SlaEventType::Breached->value)
                ->whereBetween('occurred_at', [$filters->from, $filters->to])
                ->whereIn('incident_id', $this->incidents($user, $filters)->select('incidents.id'))
                ->with('incident:id,ref,title,severity,status')
                ->latest('occurred_at')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (IncidentSlaEvent $event): array => [
                    'incident' => $this->incidentSummary($event->incident),
                    'metric' => $event->metric->value,
                    'due_at' => $event->due_at->toIso8601ZuluString(),
                    'breached_at' => $event->occurred_at->toIso8601ZuluString(),
                ])
                ->all();

            return [
                'period' => $filters->period(),
                'overall' => [
                    'resolve_pct' => $this->percent($resolved->sum('met'), $resolved->sum('total')),
                    'ack_pct' => $this->percent($acknowledged->sum('met'), $acknowledged->sum('total')),
                ],
                'by_severity' => $bySeverity,
                'breaches' => $breaches,
                'at_risk' => $this->atRisk($user, $filters),
            ];
        });
    }

    /**
     * Per responder: open incidents assigned now, incidents resolved in the period, and
     * minutes logged in the period.
     *
     * @return array{unassigned_open: int, people: list<array<string, mixed>>}
     */
    public function workload(User $user, DashboardFilters $filters): array
    {
        return $this->cache->remember($user, 'workload', $filters->cacheKey(), function () use ($user, $filters): array {
            $open = $this->incidents($user, $filters)
                ->whereIn('status', IncidentStatus::openValues())
                ->whereNotNull('assignee_id')
                ->toBase()
                ->selectRaw('assignee_id, count(*) as total, sum(case when severity <= 2 then 1 else 0 end) as high')
                ->groupBy('assignee_id')
                ->get()
                ->keyBy('assignee_id');

            $resolved = $this->incidents($user, $filters)
                ->whereBetween('resolved_at', [$filters->from, $filters->to])
                ->whereNotNull('assignee_id')
                ->toBase()
                ->selectRaw('assignee_id, count(*) as total')
                ->groupBy('assignee_id')
                ->pluck('total', 'assignee_id');

            $minutes = TimeEntry::query()
                ->whereBetween('worked_on', [$filters->period()['from'], $filters->period()['to']])
                ->whereNotNull('minutes')
                ->whereIn('incident_id', $this->incidents($user, $filters)->select('incidents.id'))
                ->toBase()
                ->selectRaw('user_id, sum(minutes) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            $userIds = collect([...$open->keys(), ...$resolved->keys(), ...$minutes->keys()])->unique()->values();
            $names = User::query()->whereIn('id', $userIds)->pluck('name', 'id');

            $people = $userIds
                ->map(fn (int|string $id): array => [
                    'user' => ['id' => (int) $id, 'name' => $names[$id] ?? 'Unknown user'],
                    'open' => (int) ($open[$id]->total ?? 0),
                    'open_high' => (int) ($open[$id]->high ?? 0),
                    'resolved' => (int) ($resolved[$id] ?? 0),
                    'minutes_logged' => (int) ($minutes[$id] ?? 0),
                ])
                ->sortBy([['open', 'desc'], ['minutes_logged', 'desc'], ['resolved', 'desc']])
                ->values()
                ->all();

            return [
                'unassigned_open' => $this->incidents($user, $filters)
                    ->whereIn('status', IncidentStatus::openValues())
                    ->whereNull('assignee_id')
                    ->count(),
                'people' => $people,
            ];
        });
    }

    /**
     * Open incidents, most severe first, then oldest first.
     *
     * @return Builder<Incident>
     */
    public function currentQuery(User $user, DashboardFilters $filters): Builder
    {
        return $this->incidents($user, $filters)
            ->whereIn('status', IncidentStatus::openValues())
            ->orderBy('severity')
            ->orderBy('reported_at')
            ->orderBy('id');
    }

    /**
     * @return Builder<Incident>
     */
    private function incidents(User $user, DashboardFilters $filters): Builder
    {
        return Incident::query()
            ->visibleTo($user)
            ->when($filters->teamId !== null, fn (Builder $query) => $query->where('incidents.team_id', $filters->teamId))
            ->when($filters->serviceId !== null, fn (Builder $query) => $query->where('incidents.service_id', $filters->serviceId));
    }

    /**
     * Open and overdue RCA action items, RCAs past due and RCAs waiting for review, plus the
     * overdue items soonest due first. Not limited to the period: overdue work is overdue
     * whenever the incident happened.
     *
     * @return array<string, mixed>
     */
    public function actionItems(User $user, DashboardFilters $filters): array
    {
        return $this->cache->remember($user, 'action-items', $filters->cacheKey(), function () use ($user, $filters): array {
            $rcas = Rca::query()->whereIn('incident_id', $this->incidents($user, $filters)->select('incidents.id'));

            return [
                ...$this->actionItemCounts($user, $filters),
                'rcas_overdue' => (clone $rcas)->where('status', '!=', RcaStatus::Approved->value)->where('due_at', '<', now())->count(),
                'rcas_in_review' => (clone $rcas)->where('status', RcaStatus::InReview->value)->count(),
                'overdue_items' => $this->actionItemsQuery($user, $filters)
                    ->overdue()
                    ->with(['owner', 'rca.incident'])
                    ->orderBy('due_date')
                    ->limit(10)
                    ->get()
                    ->map(fn (RcaActionItem $item): array => [
                        'id' => $item->id,
                        'title' => $item->title,
                        'due_date' => $item->due_date?->toDateString(),
                        'priority' => $item->priority->value,
                        'owner' => $item->owner === null ? null : ['id' => $item->owner->id, 'name' => $item->owner->name],
                        'incident' => ['id' => $item->rca->incident->id, 'ref' => $item->rca->incident->ref],
                    ])
                    ->all(),
            ];
        });
    }

    /**
     * @return array{open: int, overdue: int}
     */
    private function actionItemCounts(User $user, DashboardFilters $filters): array
    {
        return [
            'open' => $this->actionItemsQuery($user, $filters)->unfinished()->count(),
            'overdue' => $this->actionItemsQuery($user, $filters)->overdue()->count(),
        ];
    }

    /**
     * @return Builder<RcaActionItem>
     */
    private function actionItemsQuery(User $user, DashboardFilters $filters): Builder
    {
        return RcaActionItem::query()->whereHas('rca', fn (Builder $rca) => $rca
            ->whereIn('incident_id', $this->incidents($user, $filters)->select('incidents.id')));
    }

    /**
     * Figures that depend on the period, also computed for the previous period.
     *
     * @return array<string, mixed>
     */
    private function periodStats(User $user, DashboardFilters $filters): array
    {
        $window = [$filters->from, $filters->to];

        $resolved = $this->incidents($user, $filters)
            ->whereBetween('resolved_at', $window)
            ->toBase()
            ->selectRaw('TIMESTAMPDIFF(SECOND, reported_at, resolved_at) as seconds')
            ->selectRaw('case when resolve_due_at is null then null when resolved_at <= resolve_due_at then 1 else 0 end as met')
            ->get();

        $resolveMinutes = $resolved->map(fn (object $row): float => (int) $row->seconds / 60)->sort()->values();
        $withTarget = $resolved->whereNotNull('met');

        $reopened = StatusHistory::query()
            ->whereIn('from_status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value])
            ->where('to_status', IncidentStatus::Investigating->value)
            ->whereBetween('changed_at', $window)
            ->whereIn('incident_id', $this->incidents($user, $filters)->select('incidents.id'))
            ->count();

        $sources = $this->incidents($user, $filters)
            ->whereBetween('reported_at', $window)
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        return [
            'reported' => (int) $sources->sum(),
            'resolved' => $resolved->count(),
            'mtta_minutes' => $this->averageMinutes($user, $filters, 'acknowledged_at'),
            'mttm_minutes' => $this->averageMinutes($user, $filters, 'mitigated_at'),
            'mttr_minutes' => [
                'avg' => $resolveMinutes->isEmpty() ? null : round($resolveMinutes->avg(), 1),
                'median' => $this->percentile($resolveMinutes, 0.5),
                'p90' => $this->percentile($resolveMinutes, 0.9),
            ],
            'sla_compliance_pct' => $this->percent($withTarget->where('met', 1)->count(), $withTarget->count()),
            'sla_breached' => IncidentSlaEvent::query()
                ->where('type', SlaEventType::Breached->value)
                ->whereBetween('occurred_at', $window)
                ->whereIn('incident_id', $this->incidents($user, $filters)->select('incidents.id'))
                ->distinct()
                ->count('incident_id'),
            'reopen_rate_pct' => $this->percent($reopened, $resolved->count()),
            'recurring' => $this->incidents($user, $filters)
                ->whereBetween('reported_at', $window)
                ->whereNotNull('incidents.service_id')
                ->whereExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('incidents as earlier')
                    ->whereColumn('earlier.service_id', 'incidents.service_id')
                    ->whereRaw('earlier.category_id <=> incidents.category_id')
                    ->whereColumn('earlier.id', '<>', 'incidents.id')
                    ->whereColumn('earlier.reported_at', '<', 'incidents.reported_at')
                    ->whereRaw('earlier.reported_at >= DATE_SUB(incidents.reported_at, INTERVAL 30 DAY)')
                    ->whereNull('earlier.deleted_at'))
                ->count(),
            'detection' => collect(IncidentSource::cases())->mapWithKeys(fn (IncidentSource $source): array => [
                $source->value => (int) ($sources[$source->value] ?? 0),
            ])->all(),
        ];
    }

    /**
     * Average minutes from report to the given timestamp, for incidents that reached it in
     * the period.
     */
    private function averageMinutes(User $user, DashboardFilters $filters, string $column): ?float
    {
        $seconds = $this->incidents($user, $filters)
            ->whereBetween($column, [$filters->from, $filters->to])
            ->toBase()
            ->selectRaw("avg(TIMESTAMPDIFF(SECOND, reported_at, {$column})) as seconds")
            ->value('seconds');

        return $seconds === null ? null : round((float) $seconds / 60, 1);
    }

    /**
     * Open incidents whose next unmet target is at least 80% elapsed, soonest deadline first.
     *
     * @return list<array<string, mixed>>
     */
    private function atRisk(User $user, DashboardFilters $filters): array
    {
        $now = Carbon::now();

        return $this->currentQuery($user, $filters)
            ->get(['id', 'ref', 'title', 'severity', 'status', 'reported_at', 'acknowledged_at', 'mitigated_at', 'ack_due_at', 'mitigate_due_at', 'resolve_due_at'])
            ->map(function (Incident $incident) use ($now): ?array {
                [$metric, $due] = match (true) {
                    $incident->acknowledged_at === null => [SlaMetric::Acknowledge, $incident->ack_due_at],
                    $incident->mitigated_at === null => [SlaMetric::Mitigate, $incident->mitigate_due_at],
                    default => [SlaMetric::Resolve, $incident->resolve_due_at],
                };

                if ($due === null) {
                    return null;
                }

                $target = max(1, $incident->reported_at->diffInSeconds($due));
                $elapsed = $incident->reported_at->diffInSeconds($now);

                if ($elapsed / $target < self::AT_RISK_SHARE) {
                    return null;
                }

                return [
                    'incident' => $this->incidentSummary($incident),
                    'metric' => $metric->value,
                    'due_at' => $due->toIso8601ZuluString(),
                    'minutes_left' => (int) floor($now->diffInSeconds($due) / 60),
                    'breached' => $due->isPast(),
                ];
            })
            ->filter()
            ->sortBy('due_at')
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, ref: string|null, title: string, severity: int, status: string}
     */
    private function incidentSummary(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'ref' => $incident->ref,
            'title' => $incident->title,
            'severity' => $incident->severity->value,
            'status' => $incident->status->value,
        ];
    }

    /**
     * @param  array<int, int|string>  $keys
     * @return array<int|string, string>
     */
    private function labelsFor(DashboardDimension $by, array $keys): array
    {
        return match ($by) {
            DashboardDimension::Service => Service::query()->whereIn('id', $keys)->pluck('name', 'id')->all(),
            DashboardDimension::Category => Category::query()->whereIn('id', $keys)->pluck('name', 'id')->all(),
            DashboardDimension::Team => Team::query()->whereIn('id', $keys)->pluck('name', 'id')->all(),
            DashboardDimension::Severity => collect($keys)->mapWithKeys(fn (int|string $key): array => [$key => Severity::from((int) $key)->label()])->all(),
            DashboardDimension::Source => collect($keys)->mapWithKeys(fn (int|string $key): array => [$key => ucfirst((string) $key)])->all(),
        };
    }

    /**
     * Bucket keys from the start of the period to its end, each with no values yet.
     *
     * @return array<string, list<int>>
     */
    private function emptyBuckets(DashboardFilters $filters, DashboardInterval $interval): array
    {
        $cursor = $interval->bucketStart($filters->from->setTimezone($filters->timezone));
        $end = $filters->to->setTimezone($filters->timezone);
        $buckets = [];

        while ($cursor <= $end) {
            $buckets[$cursor->toDateString()] = [];
            $cursor = $interval->next($cursor);
        }

        return $buckets;
    }

    private function bucketKey(CarbonImmutable $at, string $timezone, DashboardInterval $interval): string
    {
        return $interval->bucketStart($at->setTimezone($timezone))->toDateString();
    }

    /**
     * @param  Collection<int, float>  $sorted
     */
    private function percentile(Collection $sorted, float $share): ?float
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        // Nearest-rank: the smallest value with at least $share of the values at or below it.
        $index = max(0, (int) ceil($share * $sorted->count()) - 1);

        return round($sorted[$index], 1);
    }

    private function percent(int|float|string $part, int|float|string $whole): ?float
    {
        return (float) $whole === 0.0 ? null : round((float) $part / (float) $whole * 100, 1);
    }
}
