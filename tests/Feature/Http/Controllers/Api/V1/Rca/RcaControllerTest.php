<?php

use App\Enums\IncidentStatus;
use App\Enums\RcaStatus;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentResolution;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\Team;
use App\Models\User;
use App\Notifications\RcaReadyForReview;
use App\Notifications\RcaRejected;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
});

/**
 * A resolved SEV1 incident with its resolution written, ready for an RCA.
 */
function resolvedSev1(array $attributes = []): Incident
{
    $incident = Incident::factory()->severity(Severity::Sev1)->inStatus(IncidentStatus::Resolved)->create($attributes);
    IncidentResolution::factory()->for($incident)->create();

    return $incident;
}

/**
 * An RCA in review, written by someone else, that meets every approval rule.
 */
function rcaInReview(?Incident $incident = null): Rca
{
    $rca = Rca::factory()->inStatus(RcaStatus::InReview)->create(['incident_id' => ($incident ?? resolvedSev1())->id]);
    RcaActionItem::factory()->for($rca)->create();

    return $rca;
}

describe('writing', function () {
    it('creates a draft with its timeline, due five business days after resolution', function () {
        // Resolved on a Thursday: Fri, Mon, Tue, Wed, Thu → due the next Thursday.
        $this->travelTo(Carbon::parse('2026-10-29 10:00:00', 'UTC'));
        $author = actingAsRole(RoleName::Responder);
        $incident = resolvedSev1(['assignee_id' => $author->id, 'resolved_at' => now()]);

        $this->postJson("/api/v1/incidents/{$incident->id}/rca", [
            'summary' => 'Payments failed for 40 minutes',
            'root_cause' => 'The consumer crashed on a malformed message',
            'root_cause_category' => 'code',
            'five_whys' => [['why' => 'Why did payments fail?', 'answer' => 'The queue was not drained']],
            'contributing_factors' => ['No alert on queue depth', ''],
            'timeline' => [
                ['at' => '2026-10-29T08:10:00Z', 'event' => 'Alert fired', 'actor' => 'Grafana'],
                ['at' => '2026-10-29T08:05:00Z', 'event' => 'First failed payment'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.author.id', $author->id)
            ->assertJsonPath('data.due_at', '2026-11-05T23:59:59Z')
            ->assertJsonPath('data.contributing_factors', ['No alert on queue depth'])
            // Sorted by time, whatever order they were sent in.
            ->assertJsonPath('data.timeline.0.event', 'First failed payment')
            ->assertJsonPath('data.timeline.1.actor', 'Grafana')
            ->assertJsonPath('data.missing.action_items.0', 'Add at least one action item.');
    });

    it('uses the configured number of business days', function () {
        app(SettingsService::class)->update(['rca.due_business_days' => 1]);
        $this->travelTo(Carbon::parse('2026-10-30 10:00:00', 'UTC')); // a Friday
        $author = actingAsRole(RoleName::Responder);
        $incident = resolvedSev1(['resolved_at' => now(), 'reporter_id' => $author->id]);

        $this->postJson("/api/v1/incidents/{$incident->id}/rca", [])
            ->assertCreated()
            ->assertJsonPath('data.due_at', '2026-11-02T23:59:59Z');
    });

    it('sets the due date when the incident is resolved after the RCA was started', function () {
        $manager = actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->severity(Severity::Sev2)->inStatus(IncidentStatus::Investigating)->assignedTo($manager)->create();
        $rca = Rca::factory()->create(['incident_id' => $incident->id]);
        expect($rca->due_at)->toBeNull();

        $this->postJson("/api/v1/incidents/{$incident->id}/transition", [
            'status' => 'resolved',
            'resolution' => ['summary' => 'Rolled back', 'fix_type' => 'rollback'],
        ])->assertOk();

        expect($rca->fresh()->due_at)->not->toBeNull();
    });

    it('refuses a second RCA for the same incident and one for a cancelled incident', function () {
        $author = actingAsRole(RoleName::Responder);
        $rca = Rca::factory()->create(['incident_id' => resolvedSev1(['reporter_id' => $author->id])->id]);
        $cancelled = Incident::factory()->inStatus(IncidentStatus::Cancelled)->create(['reporter_id' => $author->id]);

        $this->postJson("/api/v1/incidents/{$rca->incident_id}/rca", [])->assertConflict()->assertJsonPath('code', 'rca_exists');
        $this->postJson("/api/v1/incidents/{$cancelled->id}/rca", [])->assertUnprocessable()->assertJsonPath('code', 'incident_cancelled');
    });

    it('replaces the timeline when one is sent and keeps it otherwise', function () {
        $author = actingAsRole(RoleName::Responder);
        $rca = Rca::factory()->create(['incident_id' => resolvedSev1(['reporter_id' => $author->id])->id]);
        $rca->timelineEvents()->create(['at' => now(), 'event' => 'Old event']);

        $this->putJson("/api/v1/rca/{$rca->id}", ['lessons_learned' => 'Alert on queue depth'])
            ->assertOk()
            ->assertJsonCount(1, 'data.timeline');

        $this->putJson("/api/v1/rca/{$rca->id}", ['timeline' => [['at' => now()->toIso8601String(), 'event' => 'New event']]])
            ->assertOk()
            ->assertJsonCount(1, 'data.timeline')
            ->assertJsonPath('data.timeline.0.event', 'New event');
    });

    it('locks the analysis while in review and once approved', function (RcaStatus $status) {
        $author = actingAsRole(RoleName::Responder);
        $rca = Rca::factory()->inStatus($status)->create(['incident_id' => resolvedSev1(['reporter_id' => $author->id])->id]);

        $this->putJson("/api/v1/rca/{$rca->id}", ['summary' => 'Changed'])->assertConflict()->assertJsonPath('code', 'rca_locked');
    })->with([RcaStatus::InReview, RcaStatus::Approved]);

    it('validates the five whys and the timeline with readable messages', function () {
        $author = actingAsRole(RoleName::Responder);
        $incident = resolvedSev1(['reporter_id' => $author->id]);

        $this->postJson("/api/v1/incidents/{$incident->id}/rca", [
            'five_whys' => [['why' => 'Why?']],
            'timeline' => [['event' => 'No time']],
            'root_cause_category' => 'aliens',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'five_whys.0.answer' => 'Each of the five whys needs an answer.',
                'timeline.0.at' => 'Each timeline event needs a time.',
                'root_cause_category',
            ]);
    });
});

describe('who can write', function () {
    it('lets responders and incident managers write, not NOC or reporters', function (RoleName $role, int $status) {
        $user = actingAsRole($role);
        $incident = resolvedSev1(['reporter_id' => $user->id]);

        $this->postJson("/api/v1/incidents/{$incident->id}/rca", [])->assertStatus($status);
    })->with([
        'responder (reporter of it)' => [RoleName::Responder, 201],
        'incident manager' => [RoleName::IncidentManager, 201],
        'noc' => [RoleName::Noc, 403],
        'reporter' => [RoleName::Reporter, 403],
    ]);

    it('hides the RCA of an incident the user cannot see', function () {
        actingAsRole(RoleName::Responder);
        $rca = Rca::factory()->create(['incident_id' => resolvedSev1()->id]);

        $this->getJson("/api/v1/rca/{$rca->id}")->assertNotFound();
        $this->getJson("/api/v1/incidents/{$rca->incident_id}/rca")->assertNotFound();
        $this->putJson("/api/v1/rca/{$rca->id}", ['summary' => 'x'])->assertNotFound();
    });
});

describe('review', function () {
    it('sends for review once the approval rules are met, and tells the incident managers', function () {
        $author = actingAsRole(RoleName::Responder);
        $manager = userWithRole(RoleName::IncidentManager);
        $rca = Rca::factory()->create(['incident_id' => resolvedSev1(['reporter_id' => $author->id])->id, 'author_id' => $author->id]);
        $item = RcaActionItem::factory()->for($rca)->unplanned()->create(['title' => 'Alert on queue depth']);

        $this->postJson("/api/v1/rca/{$rca->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action_items' => '"Alert on queue depth" needs an owner and a due date.']);

        $item->update(['owner_id' => $author->id, 'due_date' => now()->addWeek()->toDateString()]);

        $this->postJson("/api/v1/rca/{$rca->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        Notification::assertSentTo($manager, RcaReadyForReview::class);
        Notification::assertNotSentTo($author, RcaReadyForReview::class);
    });

    it('needs a root cause and its category before review', function () {
        $author = actingAsRole(RoleName::IncidentManager);
        $rca = Rca::factory()->withoutRootCause()->create(['incident_id' => resolvedSev1()->id, 'author_id' => $author->id]);
        RcaActionItem::factory()->for($rca)->create();

        $this->postJson("/api/v1/rca/{$rca->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['root_cause', 'root_cause_category']);
    });

    it('needs the incident to be resolved before review', function () {
        actingAsRole(RoleName::IncidentManager);
        $incident = Incident::factory()->inStatus(IncidentStatus::Investigating)->create();
        $rca = Rca::factory()->create(['incident_id' => $incident->id]);
        RcaActionItem::factory()->for($rca)->create();

        $this->postJson("/api/v1/rca/{$rca->id}/submit")->assertUnprocessable()->assertJsonPath('code', 'incident_not_resolved');
    });

    it('lets an incident manager approve, which unlocks closing the SEV1', function () {
        $reviewer = actingAsRole(RoleName::IncidentManager);
        $rca = rcaInReview();

        $this->postJson("/api/v1/incidents/{$rca->incident_id}/transition", ['status' => 'closed'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'rca_required');

        $this->postJson("/api/v1/rca/{$rca->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewer.id', $reviewer->id)
            ->assertJsonMissingPath('data.missing');

        expect(AuditLog::where('action', 'rca.approved')->where('entity_id', $rca->id)->where('user_id', $reviewer->id)->exists())->toBeTrue();

        $this->postJson("/api/v1/incidents/{$rca->incident_id}/transition", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    });

    it('does not let the author review their own RCA', function () {
        $manager = actingAsRole(RoleName::IncidentManager);
        $rca = rcaInReview();
        $rca->update(['author_id' => $manager->id]);

        $this->postJson("/api/v1/rca/{$rca->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'Someone other than the author must review this root cause analysis.');
        $this->postJson("/api/v1/rca/{$rca->id}/reject", ['reason' => 'Not good enough'])->assertForbidden();
    });

    it('does not let responders approve', function () {
        actingAsRole(RoleName::Responder);
        $rca = rcaInReview(resolvedSev1(['reporter_id' => auth()->id()]));

        $this->postJson("/api/v1/rca/{$rca->id}/approve")->assertForbidden();
    });

    it('needs a session token to approve, not a personal token', function () {
        Sanctum::actingAs(userWithRole(RoleName::IncidentManager), ['incidents:update']);
        $rca = rcaInReview();

        $this->postJson("/api/v1/rca/{$rca->id}/approve")->assertForbidden();
    });

    it('only approves or rejects an RCA that is waiting for review', function () {
        actingAsRole(RoleName::IncidentManager);
        $rca = Rca::factory()->create(['incident_id' => resolvedSev1()->id]);
        RcaActionItem::factory()->for($rca)->create();

        $this->postJson("/api/v1/rca/{$rca->id}/approve")->assertConflict()->assertJsonPath('code', 'invalid_rca_status');
    });

    it('rejects with a reason, tells the author, and lets them edit and resubmit', function () {
        $reviewer = actingAsRole(RoleName::IncidentManager);
        $author = userWithRole(RoleName::Responder);
        $rca = rcaInReview();
        $rca->update(['author_id' => $author->id]);

        $this->postJson("/api/v1/rca/{$rca->id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/rca/{$rca->id}/reject", ['reason' => 'Explain why the alert did not fire'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Explain why the alert did not fire');

        Notification::assertSentTo($author, RcaRejected::class, fn (RcaRejected $notification) => $notification->reviewer->is($reviewer));

        Sanctum::actingAs($author, ['*']);
        $rca->incident->update(['reporter_id' => $author->id]);

        $this->putJson("/api/v1/rca/{$rca->id}", ['detection' => 'The alert threshold was too high'])->assertOk();
        $this->postJson("/api/v1/rca/{$rca->id}/submit")->assertOk()->assertJsonPath('data.status', 'in_review');
    });
});

describe('listing', function () {
    it('lists visible RCAs, waiting for review first, with counts per status', function () {
        $manager = actingAsRole(RoleName::IncidentManager);
        $approved = Rca::factory()->inStatus(RcaStatus::Approved)->create(['incident_id' => resolvedSev1()->id]);
        $inReview = rcaInReview();
        $overdue = Rca::factory()->create(['incident_id' => resolvedSev1()->id, 'due_at' => now()->subDay()]);

        $this->getJson('/api/v1/rca')
            ->assertOk()
            ->assertJsonPath('data.0.id', $inReview->id)
            ->assertJsonPath('data.0.incident.ref', $inReview->incident->ref)
            ->assertJsonPath('data.0.action_items_count', 1)
            ->assertJsonPath('meta.counts.in_review', 1)
            ->assertJsonPath('meta.counts.approved', 1)
            ->assertJsonPath('meta.counts.overdue', 1);

        $this->getJson('/api/v1/rca?status=approved')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $approved->id);
        $this->getJson('/api/v1/rca?overdue=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $overdue->id);
    });

    it('only lists RCAs of incidents the user can see', function () {
        $responder = actingAsRole(RoleName::Responder);
        $team = Team::factory()->create();
        $team->members()->attach($responder);
        $mine = Rca::factory()->create(['incident_id' => resolvedSev1(['reporter_id' => $responder->id])->id]);
        $teams = Rca::factory()->create(['incident_id' => Incident::factory()->forTeam($team)->create()->id]);
        Rca::factory()->create(['incident_id' => resolvedSev1()->id]);

        $ids = collect($this->getJson('/api/v1/rca')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids)->toBe([$mine->id, $teams->id]);
    });

    it('shows the RCA status on the incident', function () {
        actingAsRole(RoleName::IncidentManager);
        $rca = rcaInReview();

        $this->getJson("/api/v1/incidents/{$rca->incident_id}?include=rca")
            ->assertOk()
            ->assertJsonPath('data.rca.status', 'in_review')
            ->assertJsonPath('data.rca_required', true);
    });
});

describe('knowledge base', function () {
    it('shows the approved RCA with the past fix', function () {
        actingAsRole(RoleName::IncidentManager);
        $rca = Rca::factory()->inStatus(RcaStatus::Approved)->create([
            'incident_id' => resolvedSev1()->id,
            'root_cause' => 'Certificate expired',
        ]);
        RcaActionItem::factory()->for($rca)->create(['title' => 'Monitor certificate expiry']);
        $draft = Rca::factory()->create(['incident_id' => resolvedSev1()->id]);

        $this->getJson("/api/v1/knowledge-base/{$rca->incident_id}")
            ->assertOk()
            ->assertJsonPath('data.rca.root_cause', 'Certificate expired')
            ->assertJsonPath('data.rca.action_items.0.title', 'Monitor certificate expiry');

        // Drafts stay out of the knowledge base.
        $this->getJson("/api/v1/knowledge-base/{$draft->incident_id}")->assertOk()->assertJsonPath('data.rca', null);

        $this->getJson('/api/v1/knowledge-base?root_cause_category=configuration')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.incident.id', $rca->incident_id);
    });

    it('keeps the knowledge base visible to users who are not managers', function () {
        $user = User::factory()->withRole(RoleName::Responder)->create();
        Sanctum::actingAs($user, ['*']);
        $rca = Rca::factory()->inStatus(RcaStatus::Approved)->create(['incident_id' => resolvedSev1(['reporter_id' => $user->id])->id]);

        $this->getJson('/api/v1/knowledge-base')->assertOk()->assertJsonPath('data.0.rca.root_cause', $rca->root_cause);
    });
});
