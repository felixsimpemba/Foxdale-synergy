<?php

use App\Enums\IncidentStatus;
use App\Enums\RcaStatus;
use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * An RCA on an incident the user reported, so responders can see it.
 */
function rcaFor(User $user, RcaStatus $status = RcaStatus::Draft): Rca
{
    $incident = Incident::factory()->inStatus(IncidentStatus::Resolved)->create(['reporter_id' => $user->id]);

    return Rca::factory()->inStatus($status)->create(['incident_id' => $incident->id]);
}

describe('adding and editing', function () {
    it('adds an item, which starts open', function () {
        $writer = actingAsRole(RoleName::Responder);
        $owner = User::factory()->create();
        $rca = rcaFor($writer);

        $this->postJson("/api/v1/rca/{$rca->id}/action-items", [
            'type' => 'preventive',
            'title' => 'Alert when consumer queue depth exceeds 500 for 5 minutes',
            'owner_id' => $owner->id,
            'due_date' => '2026-11-11',
            'priority' => 'high',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.owner.name', $owner->name)
            ->assertJsonPath('data.due_date', '2026-11-11')
            ->assertJsonPath('data.incident.id', $rca->incident_id);
    });

    it('refuses an inactive owner and a status on create', function () {
        $writer = actingAsRole(RoleName::Responder);
        $rca = rcaFor($writer);
        $inactive = User::factory()->create(['is_active' => false]);

        $this->postJson("/api/v1/rca/{$rca->id}/action-items", [
            'type' => 'process',
            'title' => 'Write a runbook',
            'owner_id' => $inactive->id,
            'status' => 'done',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_id' => 'Choose an active user as the owner.', 'status']);
    });

    it('can still add items to an approved RCA, since work continues after approval', function () {
        $writer = actingAsRole(RoleName::Responder);
        $rca = rcaFor($writer, RcaStatus::Approved);

        $this->postJson("/api/v1/rca/{$rca->id}/action-items", ['type' => 'detection', 'title' => 'Dashboard for retries'])
            ->assertCreated();
    });

    it('needs a reason for "won\'t do"', function () {
        $writer = actingAsRole(RoleName::Responder);
        $item = RcaActionItem::factory()->for(rcaFor($writer))->create();

        $this->putJson("/api/v1/action-items/{$item->id}", ['status' => 'wont_do'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note' => 'Give a reason for not doing this item.']);

        $this->putJson("/api/v1/action-items/{$item->id}", ['status' => 'wont_do', 'note' => 'Vendor is replacing the system'])
            ->assertOk()
            ->assertJsonPath('data.status', 'wont_do');
    });

    it('stamps who finished an item, and clears it when reopened', function () {
        $writer = actingAsRole(RoleName::Responder);
        $item = RcaActionItem::factory()->for(rcaFor($writer))->create();

        $this->postJson("/api/v1/action-items/{$item->id}/complete", ['note' => 'Alert added in Grafana'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_by.id', $writer->id)
            ->assertJsonPath('data.note', 'Alert added in Grafana');

        $this->postJson("/api/v1/action-items/{$item->id}/complete")->assertConflict();

        $this->putJson("/api/v1/action-items/{$item->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);
    });

    it('deletes an item only while the RCA can still be edited', function () {
        $writer = actingAsRole(RoleName::Responder);
        $draftItem = RcaActionItem::factory()->for(rcaFor($writer))->create();
        $reviewItem = RcaActionItem::factory()->for(rcaFor($writer, RcaStatus::InReview))->create();

        $this->deleteJson("/api/v1/action-items/{$draftItem->id}")->assertNoContent();
        $this->deleteJson("/api/v1/action-items/{$reviewItem->id}")->assertConflict()->assertJsonPath('code', 'rca_locked');
    });
});

describe('owners', function () {
    it('lets the owner update the status and note of their item, nothing else', function () {
        // NOC has no rca.write and cannot see the incident, but owns the item.
        $owner = userWithRole(RoleName::Noc);
        $item = RcaActionItem::factory()->create(['owner_id' => $owner->id]);
        Sanctum::actingAs($owner, ['*']);

        $this->putJson("/api/v1/action-items/{$item->id}", ['status' => 'in_progress', 'note' => 'Started'])->assertOk();
        $this->postJson("/api/v1/action-items/{$item->id}/complete")->assertOk();

        $this->putJson("/api/v1/action-items/{$item->id}", ['title' => 'Something easier'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'Only the status and note can be changed by the owner.']);
    });

    it('hides items on incidents the user cannot see, unless they own them', function () {
        $user = actingAsRole(RoleName::Responder);
        $hidden = RcaActionItem::factory()->create();
        $owned = RcaActionItem::factory()->create(['owner_id' => $user->id]);

        $this->putJson("/api/v1/action-items/{$hidden->id}", ['status' => 'done'])->assertNotFound();

        $this->getJson('/api/v1/action-items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id)
            ->assertJsonPath('meta.counts.mine', 1);
    });
});

describe('listing', function () {
    it('filters by owner, status and overdue, unfinished soonest due first', function () {
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00'));
        $manager = actingAsRole(RoleName::IncidentManager);
        $mineLater = RcaActionItem::factory()->create(['owner_id' => $manager->id, 'due_date' => '2026-11-20']);
        $mineOverdue = RcaActionItem::factory()->overdue()->create(['owner_id' => $manager->id]);
        $done = RcaActionItem::factory()->overdue()->create(['status' => 'done']);
        $dueToday = RcaActionItem::factory()->create(['due_date' => '2026-10-29']);

        $this->getJson('/api/v1/action-items?owner_id=me')
            ->assertOk()
            ->assertJsonPath('data.0.id', $mineOverdue->id)
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.1.id', $mineLater->id);

        // Due today is not overdue yet; finished items never are.
        $this->getJson('/api/v1/action-items?overdue=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts.overdue', 1)
            ->assertJsonPath('meta.counts.open', 3);

        $this->getJson('/api/v1/action-items?status=done')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $done->id);
        expect($dueToday->isOverdue())->toBeFalse();
    });

    it('lists the items of one RCA', function () {
        $writer = actingAsRole(RoleName::Responder);
        $rca = rcaFor($writer);
        RcaActionItem::factory()->for($rca)->count(2)->create();

        $this->getJson("/api/v1/rca/{$rca->id}/action-items")->assertOk()->assertJsonCount(2, 'data');
    });

    it('counts open and overdue items on the dashboard', function () {
        actingAsRole(RoleName::IncidentManager);
        RcaActionItem::factory()->create();
        RcaActionItem::factory()->overdue()->create(['title' => 'Rotate the API key']);
        Rca::factory()->inStatus(RcaStatus::InReview)->create();

        $this->getJson('/api/v1/dashboard/action-items')
            ->assertOk()
            ->assertJsonPath('data.open', 2)
            ->assertJsonPath('data.overdue', 1)
            ->assertJsonPath('data.rcas_in_review', 1)
            ->assertJsonPath('data.overdue_items.0.title', 'Rotate the API key');

        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.action_items', ['open' => 2, 'overdue' => 1]);
    });
});
