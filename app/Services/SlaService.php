<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\SlaEventType;
use App\Enums\SlaMetric;
use App\Events\SlaBreached;
use App\Events\SlaWarningRaised;
use App\Models\Incident;
use App\Models\IncidentSlaEvent;
use App\Models\SlaPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * SLA targets per severity, deadlines per incident, and warning/breach detection.
 * Clocks run 24/7 from reported_at; business-hours calendars would plug in here.
 */
class SlaService
{
    private const CACHE_KEY = 'incidents.sla_policies';

    /**
     * Target minutes for a severity, from sla_policies or the config defaults.
     *
     * @return array{ack: int, mitigate: int, resolve: int}
     */
    public function targetsFor(Severity $severity): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => SlaPolicy::query()
            ->get()
            ->mapWithKeys(fn (SlaPolicy $policy): array => [$policy->severity->value => [
                'ack' => $policy->ack_minutes,
                'mitigate' => $policy->mitigate_minutes,
                'resolve' => $policy->resolve_minutes,
            ]])
            ->all());

        return $stored[$severity->value] ?? config("incidents.sla.defaults.{$severity->value}");
    }

    public function forgetCachedPolicies(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Set the three deadlines from reported_at and the current severity. Does not save.
     */
    public function applyTargets(Incident $incident): void
    {
        $targets = $this->targetsFor($incident->severity);

        $incident->ack_due_at = $incident->reported_at->copy()->addMinutes($targets['ack']);
        $incident->mitigate_due_at = $incident->reported_at->copy()->addMinutes($targets['mitigate']);
        $incident->resolve_due_at = $incident->reported_at->copy()->addMinutes($targets['resolve']);
    }

    public function isMet(Incident $incident, SlaMetric $metric): bool
    {
        return match ($metric) {
            SlaMetric::Acknowledge => $incident->acknowledged_at !== null,
            // Resolving without a separate mitigation step also meets the mitigation target.
            SlaMetric::Mitigate => $incident->mitigated_at !== null || $incident->resolved_at !== null,
            SlaMetric::Resolve => $incident->resolved_at !== null,
        };
    }

    /**
     * Raise warnings (80% elapsed) and breaches for targets that are still open.
     * Each warning and breach is raised at most once per incident and metric.
     */
    public function evaluate(Incident $incident, Carbon $now): void
    {
        if ($incident->status === IncidentStatus::Cancelled) {
            return;
        }

        foreach (SlaMetric::cases() as $metric) {
            /** @var Carbon|null $due */
            $due = $incident->{$metric->dueColumn()};

            if ($due === null || $this->isMet($incident, $metric)) {
                continue;
            }

            if ($now->greaterThanOrEqualTo($due)) {
                $this->record($incident, $metric, SlaEventType::Breached, $due, $now);

                continue;
            }

            $total = $incident->reported_at->diffInSeconds($due);
            $elapsed = $incident->reported_at->diffInSeconds($now);

            if ($total > 0 && $elapsed / $total >= config('incidents.sla.warning_ratio')) {
                $this->record($incident, $metric, SlaEventType::Warning, $due, $now);
            }
        }
    }

    /**
     * After a status change: record a breach for any target that was met, but late,
     * before the every-minute check noticed.
     */
    public function recordLateCompletions(Incident $incident): void
    {
        foreach (SlaMetric::cases() as $metric) {
            /** @var Carbon|null $due */
            $due = $incident->{$metric->dueColumn()};
            $metAt = $metric === SlaMetric::Mitigate
                ? ($incident->mitigated_at ?? $incident->resolved_at)
                : $incident->{$metric->metColumn()};

            if ($due !== null && $metAt !== null && $metAt->greaterThan($due)) {
                $this->record($incident, $metric, SlaEventType::Breached, $due, $metAt);
            }
        }
    }

    private function record(Incident $incident, SlaMetric $metric, SlaEventType $type, Carbon $due, Carbon $at): void
    {
        $inserted = IncidentSlaEvent::query()->insertOrIgnore([
            'incident_id' => $incident->id,
            'metric' => $metric->value,
            'type' => $type->value,
            'due_at' => $due,
            'occurred_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        if ($type === SlaEventType::Breached) {
            if (! $incident->sla_breached) {
                $incident->forceFill(['sla_breached' => true])->save();
            }

            SlaBreached::dispatch($incident, $metric);

            return;
        }

        SlaWarningRaised::dispatch($incident, $metric);
    }
}
