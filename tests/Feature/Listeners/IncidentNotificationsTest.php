<?php

use App\Enums\IncidentStatus;
use App\Enums\RoleName;
use App\Enums\Severity;
use App\Enums\SlaMetric;
use App\Events\IncidentAssigned;
use App\Events\IncidentStatusChanged;
use App\Events\SlaBreached;
use App\Events\SlaWarningRaised;
use App\Listeners\NotifyOnIncidentAssigned;
use App\Listeners\NotifyOnSlaBreach;
use App\Listeners\NotifyOnSlaWarning;
use App\Listeners\NotifyOnStatusChanged;
use App\Models\Incident;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InAppAlert;
use App\Notifications\IncidentNotice;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\PushService;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakePushSender;

beforeEach(function () {
    Notification::fake();
    $this->notifications = new NotificationService(
        app(NotificationPreferences::class),
        new PushService(new FakePushSender),
    );
});

it('tells the new assignee, but not someone who assigned it to themselves', function () {
    $assignee = userWithRole(RoleName::Noc);
    $incident = Incident::factory()->assignedTo($assignee)->create();

    (new NotifyOnIncidentAssigned($this->notifications))->handle(new IncidentAssigned($incident, null, User::factory()->create()));

    Notification::assertSentTo($assignee, IncidentNotice::class, fn (IncidentNotice $mail) => str_contains($mail->subjectLine, $incident->ref));
    Notification::assertSentTo($assignee, InAppAlert::class);

    Notification::fake();
    (new NotifyOnIncidentAssigned($this->notifications))->handle(new IncidentAssigned($incident, User::factory()->create(), $assignee));
    Notification::assertNothingSent();
});

it('tells the reporter, assignee and watchers when the status changes, but not the person who changed it', function () {
    $actor = User::factory()->create();
    $reporter = User::factory()->create();
    $watcher = User::factory()->create();
    $incident = Incident::factory()->create(['reporter_id' => $reporter->id, 'assignee_id' => $actor->id]);
    $incident->watchers()->attach($watcher);

    (new NotifyOnStatusChanged($this->notifications))->handle(
        new IncidentStatusChanged($incident, IncidentStatus::Investigating, IncidentStatus::Resolved, $actor),
    );

    // Status changes reach the bell and push by default; email is opt-in for this event.
    Notification::assertSentTo([$reporter, $watcher], InAppAlert::class);
    Notification::assertNotSentTo($actor, InAppAlert::class);
});

it('warns the assignee and the team lead at 80% of an SLA target', function () {
    $lead = User::factory()->create();
    $team = Team::factory()->create();
    $team->addMember($lead, isLead: true);
    $assignee = User::factory()->create();
    $incident = Incident::factory()->forTeam($team)->assignedTo($assignee)->create();

    (new NotifyOnSlaWarning($this->notifications))->handle(new SlaWarningRaised($incident, SlaMetric::Acknowledge));

    Notification::assertSentTo([$assignee, $lead], InAppAlert::class);
});

it('tells the assignee, the team lead and every incident manager when an SLA is breached', function () {
    $manager = userWithRole(RoleName::IncidentManager);
    $lead = User::factory()->create();
    $team = Team::factory()->create();
    $team->addMember($lead, isLead: true);
    $assignee = User::factory()->create();
    $incident = Incident::factory()->forTeam($team)->assignedTo($assignee)->severity(Severity::Sev1)->create();

    (new NotifyOnSlaBreach($this->notifications))->handle(new SlaBreached($incident, SlaMetric::Resolve));

    Notification::assertSentTo([$assignee, $lead, $manager], IncidentNotice::class);
});
