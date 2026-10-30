<?php

namespace App\Services;

use App\Enums\ActionItemStatus;
use App\Enums\IncidentStatus;
use App\Enums\NotificationEvent;
use App\Enums\RcaStatus;
use App\Exceptions\ApiException;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\User;
use App\Notifications\RcaLinks;
use App\Notifications\RcaReadyForReview;
use App\Notifications\RcaRejected;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Recipients;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The RCA workflow (specification section 8): draft → in_review → approved or rejected.
 * Approval needs a root cause, at least one action item, and an owner and due date on every
 * item; the reviewer cannot be the author (checked in RcaPolicy::review).
 */
class RcaService
{
    public function __construct(
        private AuditLogger $audit,
        private SettingsService $settings,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated RCA fields, optionally with timeline[]
     */
    public function create(Incident $incident, User $author, array $data): Rca
    {
        if ($incident->status === IncidentStatus::Cancelled) {
            throw new ApiException('Cancelled incidents do not get a root cause analysis.', 'incident_cancelled');
        }

        return DB::transaction(function () use ($incident, $author, $data): Rca {
            if (Rca::query()->where('incident_id', $incident->id)->lockForUpdate()->exists()) {
                throw new ApiException('This incident already has a root cause analysis.', 'rca_exists', 409);
            }

            $rca = Rca::create([
                ...$this->fields($data),
                'incident_id' => $incident->id,
                'author_id' => $author->id,
                'due_at' => $incident->resolved_at === null ? null : $this->dueAt($incident->resolved_at),
            ]);

            $this->replaceTimeline($rca, $data['timeline'] ?? null);

            return $rca;
        });
    }

    /**
     * Edit while in draft or rejected.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Rca $rca, array $data): Rca
    {
        if (! $rca->status->isEditable()) {
            throw new ApiException(
                $rca->status === RcaStatus::Approved
                    ? 'An approved root cause analysis cannot be changed.'
                    : 'This root cause analysis is waiting for review. Ask the reviewer to reject it to make changes.',
                'rca_locked',
                409,
            );
        }

        return DB::transaction(function () use ($rca, $data): Rca {
            $rca->update($this->fields($data));
            $this->replaceTimeline($rca, $data['timeline'] ?? null);

            return $rca;
        });
    }

    /**
     * Send for review. Checks the approval rules now, so a reviewer never gets one that
     * cannot be approved.
     */
    public function submit(Rca $rca, User $actor): Rca
    {
        if (! $rca->status->isEditable()) {
            throw new ApiException('Only a draft or rejected root cause analysis can be sent for review.', 'invalid_rca_status', 409);
        }

        $incident = Incident::query()->findOrFail($rca->incident_id);

        if (! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            throw new ApiException('Resolve the incident before sending its root cause analysis for review.', 'incident_not_resolved');
        }

        $this->ensureApprovable($rca);

        $rca->forceFill(['status' => RcaStatus::InReview, 'submitted_at' => now()])->save();
        $this->audit->record('rca.submitted', $rca, actor: $actor);

        $reviewers = $this->reviewers()->reject(fn (User $user): bool => $user->id === $rca->author_id);

        $this->notifications->send(
            $reviewers,
            NotificationEvent::RcaReadyForReview,
            $rca,
            [
                'title' => "RCA ready for review: {$incident->ref}",
                'body' => $incident->title,
                'url' => RcaLinks::incident($incident),
            ],
            new RcaReadyForReview($rca, $incident, $actor),
            $actor,
        );

        return $rca;
    }

    public function approve(Rca $rca, User $reviewer): Rca
    {
        $this->ensureInReview($rca);
        $this->ensureApprovable($rca);

        $rca->forceFill([
            'status' => RcaStatus::Approved,
            'reviewer_id' => $reviewer->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();

        $this->audit->record('rca.approved', $rca, actor: $reviewer);

        return $rca;
    }

    public function reject(Rca $rca, User $reviewer, string $reason): Rca
    {
        $this->ensureInReview($rca);

        $rca->forceFill([
            'status' => RcaStatus::Rejected,
            'reviewer_id' => $reviewer->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->record('rca.rejected', $rca, ['reason' => $reason], $reviewer);

        $author = $rca->author_id === null ? null : User::query()->find($rca->author_id);
        $incident = Incident::query()->findOrFail($rca->incident_id);

        $this->notifications->send(
            [$author],
            NotificationEvent::RcaRejected,
            $rca,
            [
                'title' => "RCA returned: {$incident->ref}",
                'body' => (string) $rca->rejection_reason,
                'url' => RcaLinks::incident($incident),
            ],
            new RcaRejected($rca, $incident, $reviewer),
            $reviewer,
        );

        return $rca;
    }

    /**
     * @param  array<string, mixed>  $data  type, title, owner_id, due_date, priority
     */
    public function addActionItem(Rca $rca, array $data): RcaActionItem
    {
        return $rca->actionItems()->create($data);
    }

    /**
     * Edit an item. Moving to done stamps who finished it; moving away from done clears that.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateActionItem(RcaActionItem $item, User $actor, array $data): RcaActionItem
    {
        $item->fill($data);

        if ($item->isDirty('status')) {
            $this->stampCompletion($item, $actor);
        }

        $item->save();

        return $item;
    }

    public function completeActionItem(RcaActionItem $item, User $actor, ?string $note): RcaActionItem
    {
        if ($item->status === ActionItemStatus::Done) {
            throw new ApiException('This action item is already done.', 'action_item_done', 409);
        }

        $item->status = ActionItemStatus::Done;
        $item->note = filled($note) ? $note : $item->note;
        $this->stampCompletion($item, $actor);
        $item->save();

        return $item;
    }

    /**
     * Due date for an RCA: the configured number of business days (Monday to Friday, in the
     * display timezone) after resolution, at the end of that day.
     */
    public function dueAt(Carbon $resolvedAt): Carbon
    {
        $timezone = (string) $this->settings->get('display_timezone');
        $day = $resolvedAt->copy()->setTimezone($timezone);
        $remaining = max(1, (int) $this->settings->get('rca.due_business_days'));

        while ($remaining > 0) {
            $day->addDay();

            if (! $day->isWeekend()) {
                $remaining--;
            }
        }

        return $day->endOfDay()->utc();
    }

    /**
     * Keep the due date in step with the incident: set when it is resolved (again).
     * Approved RCAs keep theirs.
     */
    public function syncDueDate(Incident $incident): void
    {
        if ($incident->resolved_at === null) {
            return;
        }

        Rca::query()
            ->where('incident_id', $incident->id)
            ->where('status', '!=', RcaStatus::Approved->value)
            ->first()
            ?->forceFill(['due_at' => $this->dueAt($incident->resolved_at)])
            ->save();
    }

    /**
     * Active incident managers: they review RCAs and get the overdue digests.
     *
     * @return Collection<int, User>
     */
    public function reviewers(): Collection
    {
        return Recipients::incidentManagers();
    }

    private function ensureApprovable(Rca $rca): void
    {
        $problems = $rca->approvalProblems();

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    private function ensureInReview(Rca $rca): void
    {
        if ($rca->status !== RcaStatus::InReview) {
            throw new ApiException('Only a root cause analysis waiting for review can be approved or rejected.', 'invalid_rca_status', 409);
        }
    }

    private function stampCompletion(RcaActionItem $item, User $actor): void
    {
        $done = $item->status === ActionItemStatus::Done;

        $item->forceFill([
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $actor->id : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return collect($data)->except('timeline')->all();
    }

    /**
     * @param  list<array{at: string, event: string, actor?: string|null}>|null  $events  null leaves the timeline as is
     */
    private function replaceTimeline(Rca $rca, ?array $events): void
    {
        if ($events === null) {
            return;
        }

        $rca->timelineEvents()->delete();

        foreach ($events as $event) {
            $rca->timelineEvents()->create([
                'at' => Carbon::parse($event['at']),
                'event' => $event['event'],
                'actor' => $event['actor'] ?? null,
            ]);
        }
    }
}
