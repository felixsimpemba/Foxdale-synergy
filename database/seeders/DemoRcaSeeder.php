<?php

namespace Database\Seeders;

use App\Enums\ActionItemPriority;
use App\Enums\ActionItemStatus;
use App\Enums\ActionItemType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\PermissionKey;
use App\Enums\RcaStatus;
use App\Enums\RecurrenceRisk;
use App\Enums\RoleName;
use App\Enums\RootCauseCategory;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\User;
use App\Services\Dashboard\DashboardCache;
use App\Services\RcaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo root cause analyses for resolved SEV1 and SEV2 demo incidents: approved ones (with
 * the incident closed), some waiting for review or returned, recent drafts, and action
 * items of which some are overdue. Only touches incidents without an RCA, so it can run
 * again, and on a database seeded before RCAs existed:
 *
 *     php artisan db:seed --class=DemoRcaSeeder
 */
class DemoRcaSeeder extends Seeder
{
    public function __construct(private RcaService $rcas) {}

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            throw new RuntimeException('DemoRcaSeeder only runs in the local or testing environment.');
        }

        $manager = User::query()->where('email', 'grace@foxdale.local')->first();

        if ($manager === null) {
            $this->command?->warn('No demo data found. Run DemoDataSeeder first.');

            return;
        }

        $writers = User::query()->permission(PermissionKey::RcaWrite->value)
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('name', RoleName::IncidentManager->value))
            ->get();
        $scenarios = collect(DemoDataSeeder::scenarios())->keyBy('title');

        $incidents = Incident::query()
            ->where('status', IncidentStatus::Resolved->value)
            ->whereIn('severity', [Severity::Sev1->value, Severity::Sev2->value])
            ->whereNotNull('resolved_at')
            ->whereDoesntHave('rca')
            ->with(['assignee.roles', 'service'])
            ->orderBy('id')
            ->get();

        Model::withoutEvents(fn () => DB::transaction(function () use ($incidents, $writers, $manager, $scenarios): void {
            foreach ($incidents as $incident) {
                // Seeded per incident: a re-run makes the same choice, including "no RCA yet".
                mt_srand(2027 + $incident->id);
                $this->writeRca($incident, $scenarios->get($incident->title), $writers, $manager);
            }
        }));

        app(DashboardCache::class)->bust();
        $this->command?->info("Root cause analyses added to {$incidents->count()} resolved SEV1 and SEV2 incidents.");
    }

    /**
     * @param  array<string, mixed>|null  $scenario
     * @param  Collection<int, User>  $writers
     */
    private function writeRca(Incident $incident, ?array $scenario, Collection $writers, User $manager): void
    {
        $daysSince = $incident->resolved_at->diffInDays(now());
        $roll = mt_rand(1, 100);

        $status = match (true) {
            $daysSince > 10 && $roll <= 88 => RcaStatus::Approved,
            $daysSince > 10 => $roll <= 94 ? RcaStatus::InReview : RcaStatus::Rejected,
            $daysSince > 4 => $roll <= 40 ? RcaStatus::InReview : ($roll <= 80 ? RcaStatus::Draft : null),
            default => $roll <= 50 ? RcaStatus::Draft : null,
        };

        if ($status === null || $scenario === null) {
            return;
        }

        // The owner writes it when they can; otherwise a responder does. Never the reviewer.
        $author = $incident->assignee !== null && $incident->assignee->can(PermissionKey::RcaWrite->value)
            ? $incident->assignee
            : $writers[mt_rand(0, $writers->count() - 1)];
        $written = $incident->resolved_at->copy()->addHours(mt_rand(20, 70));
        $complete = $status !== RcaStatus::Draft || mt_rand(1, 2) === 1;

        $rca = new Rca([
            'incident_id' => $incident->id,
            'author_id' => $author->id,
            'summary' => $incident->description,
            'impact' => $incident->impact,
            'detection' => $incident->source === IncidentSource::Monitoring
                ? 'Detected by a monitoring alert, '.$this->minutes($incident->started_at, $incident->reported_at).' after it started.'
                : 'Reported by a person, '.$this->minutes($incident->started_at, $incident->reported_at).' after it started. No alert fired.',
            'root_cause' => $complete ? $scenario['summary'] : null,
            'root_cause_category' => $complete ? $this->category($scenario['category']) : null,
            'contributing_factors' => $complete ? ['No alert on the early signs', 'The runbook did not cover this case'] : [],
            'five_whys' => $complete ? [
                ['why' => 'Why were customers affected?', 'answer' => $incident->impact],
                ['why' => 'Why did that happen?', 'answer' => $scenario['summary']],
                ['why' => 'Why was it not caught sooner?', 'answer' => 'Nothing alerted on it; we heard from customers first.'],
            ] : [],
            'went_well' => ['Quick escalation to the owning team', 'Clear status updates for stakeholders'],
            'went_wrong' => ['Took a while to find the right dashboard'],
            'lessons_learned' => $complete ? $scenario['permanent_fix'] : null,
            'recurrence_risk' => [RecurrenceRisk::Low, RecurrenceRisk::Medium, RecurrenceRisk::High][mt_rand(0, 2)],
            'due_at' => $this->rcas->dueAt($incident->resolved_at),
        ]);

        $submitted = $status === RcaStatus::Draft ? null : $written->copy()->addHours(mt_rand(2, 30));
        $reviewed = $submitted?->copy()->addHours(mt_rand(3, 48));

        $rca->forceFill([
            'status' => $status,
            'created_at' => $written,
            'updated_at' => $reviewed ?? $submitted ?? $written,
            'submitted_at' => $submitted,
            'reviewer_id' => in_array($status, [RcaStatus::Approved, RcaStatus::Rejected], true) ? $manager->id : null,
            'approved_at' => $status === RcaStatus::Approved ? $reviewed : null,
            'rejected_at' => $status === RcaStatus::Rejected ? $reviewed : null,
            'rejection_reason' => $status === RcaStatus::Rejected ? 'Explain why monitoring did not catch this, and add a detection action item.' : null,
        ])->save();

        $this->writeTimeline($rca, $incident);

        if ($complete) {
            $this->writeActionItems($rca, $incident, $scenario, $author, $status);
        }

        if ($status === RcaStatus::Approved) {
            $this->close($incident, $reviewed, $manager);
        }
    }

    private function writeTimeline(Rca $rca, Incident $incident): void
    {
        $events = array_filter([
            [$incident->started_at, 'First customer impact', null],
            [$incident->reported_at, 'Incident reported', $incident->source === IncidentSource::Monitoring ? 'Monitoring alert' : 'NOC'],
            [$incident->acknowledged_at, 'Acknowledged by the owning team', $incident->assignee?->name],
            [$incident->mitigated_at, 'Impact reduced', $incident->assignee?->name],
            [$incident->resolved_at, 'Fix confirmed, incident resolved', $incident->assignee?->name],
        ], fn (array $event): bool => $event[0] !== null);

        foreach ($events as [$at, $event, $actor]) {
            $rca->timelineEvents()->create(['at' => $at, 'event' => $event, 'actor' => $actor]);
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function writeActionItems(Rca $rca, Incident $incident, array $scenario, User $author, RcaStatus $status): void
    {
        $items = [
            [ActionItemType::Preventive, $scenario['permanent_fix'], ActionItemPriority::High, 10],
            [ActionItemType::Detection, "Add an alert for the early signs of this on {$incident->service?->name}", ActionItemPriority::Medium, 14],
        ];

        if (mt_rand(1, 2) === 1) {
            $items[] = [ActionItemType::Process, "Update the runbook for {$incident->service?->name}", ActionItemPriority::Low, 21];
        }

        foreach ($items as [$type, $title, $priority, $days]) {
            $due = $incident->resolved_at->copy()->addDays($days);
            // Older approved work is mostly done; a little of it slipped and is now overdue.
            $done = $status === RcaStatus::Approved && $due->isPast() && mt_rand(1, 100) <= 88;

            // Unfinished work was mostly re-planned; about one in four is left overdue.
            if (! $done && $due->isPast() && mt_rand(1, 4) > 1) {
                $due = now()->addDays(mt_rand(2, 30));
            }
            $started = ! $done && mt_rand(1, 3) === 1;

            $item = $rca->actionItems()->make([
                'type' => $type,
                'title' => $title,
                'owner_id' => $incident->assignee_id ?? $author->id,
                'due_date' => $due->toDateString(),
                'priority' => $priority,
                'status' => $done ? ActionItemStatus::Done : ($started ? ActionItemStatus::InProgress : ActionItemStatus::Open),
                'note' => $done ? 'Done and verified in production.' : null,
            ]);
            $item->forceFill([
                'completed_at' => $done ? $due->copy()->subDays(mt_rand(0, 4)) : null,
                'completed_by' => $done ? ($incident->assignee_id ?? $author->id) : null,
            ])->save();
        }
    }

    private function close(Incident $incident, Carbon $approvedAt, User $manager): void
    {
        $closedAt = $approvedAt->copy()->addHours(mt_rand(1, 12));
        $previous = $incident->statusHistory()->latest('changed_at')->first();

        $incident->forceFill(['status' => IncidentStatus::Closed, 'closed_at' => $closedAt])->save();
        $incident->statusHistory()->create([
            'from_status' => IncidentStatus::Resolved,
            'to_status' => IncidentStatus::Closed,
            'user_id' => $manager->id,
            'note' => 'RCA approved',
            'changed_at' => $closedAt,
            'seconds_in_previous' => $previous === null ? null : (int) $previous->changed_at->diffInSeconds($closedAt),
        ]);
    }

    private function category(string $scenarioCategory): RootCauseCategory
    {
        return match ($scenarioCategory) {
            'Application' => RootCauseCategory::Code,
            'Infrastructure', 'Network' => RootCauseCategory::Infrastructure,
            'Database' => RootCauseCategory::Capacity,
            'Third-party' => RootCauseCategory::ThirdParty,
            'Security' => RootCauseCategory::Security,
            default => RootCauseCategory::Configuration,
        };
    }

    private function minutes(?Carbon $from, Carbon $to): string
    {
        return $from === null ? 'some time' : (int) $from->diffInMinutes($to).' minutes';
    }
}
