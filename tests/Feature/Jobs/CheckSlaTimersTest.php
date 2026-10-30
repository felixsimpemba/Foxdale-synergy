<?php

use App\Actions\Incidents\CreateIncident;
use App\Actions\Incidents\TransitionIncident;
use App\Enums\IncidentStatus;
use App\Enums\SlaEventType;
use App\Enums\SlaMetric;
use App\Events\SlaBreached;
use App\Events\SlaWarningRaised;
use App\Jobs\CheckSlaTimers;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentSlaEvent;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * A SEV1 incident: acknowledge within 5 minutes, mitigate within 30, resolve within 4 hours.
 */
function reportSev1(): Incident
{
    return app(CreateIncident::class)->handle(User::factory()->create(), [
        'title' => 'Payments down',
        'description' => 'All deposits failing',
        'severity' => 1,
        'service_id' => Service::factory()->create()->id,
        'category_id' => Category::factory()->create()->id,
    ]);
}

it('raises a warning once 80% of the acknowledge target has elapsed', function () {
    $this->freezeTime();
    $incident = reportSev1();
    Event::fake([SlaWarningRaised::class, SlaBreached::class]);

    $this->travel(3)->minutes();
    dispatch_sync(new CheckSlaTimers);
    Event::assertNotDispatched(SlaWarningRaised::class);

    $this->travel(1)->minutes();
    dispatch_sync(new CheckSlaTimers);

    Event::assertDispatchedTimes(SlaWarningRaised::class, 1);
    Event::assertDispatched(SlaWarningRaised::class, fn (SlaWarningRaised $event) => $event->metric === SlaMetric::Acknowledge);
    Event::assertNotDispatched(SlaBreached::class);
    expect($incident->fresh()->sla_breached)->toBeFalse();
});

it('raises a breach once, however often the check runs', function () {
    $this->freezeTime();
    $incident = reportSev1();
    Event::fake([SlaWarningRaised::class, SlaBreached::class]);

    $this->travel(6)->minutes();
    dispatch_sync(new CheckSlaTimers);
    dispatch_sync(new CheckSlaTimers);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    expect($incident->fresh()->sla_breached)->toBeTrue()
        ->and(IncidentSlaEvent::where('type', SlaEventType::Breached)->pluck('metric')->all())->toBe([SlaMetric::Acknowledge]);
});

it('ignores targets that were met', function () {
    $this->freezeTime();
    $incident = reportSev1();
    $this->travel(2)->minutes();
    app(TransitionIncident::class)->handle($incident, User::factory()->create(), IncidentStatus::Acknowledged);
    Event::fake([SlaBreached::class]);

    $this->travel(10)->minutes();
    dispatch_sync(new CheckSlaTimers);

    Event::assertNotDispatched(SlaBreached::class);
});

it('ignores cancelled incidents', function () {
    $this->freezeTime();
    $incident = reportSev1();
    app(TransitionIncident::class)->handle($incident, User::factory()->create(), IncidentStatus::Cancelled, ['note' => 'False alarm']);
    Event::fake([SlaWarningRaised::class, SlaBreached::class]);

    $this->travel(1)->days();
    dispatch_sync(new CheckSlaTimers);

    Event::assertNothingDispatched();
});

it('records a breach when a target is met late between two checks', function () {
    $this->freezeTime();
    $incident = reportSev1();

    $this->travel(5)->minutes();
    $this->travel(30)->seconds();
    app(TransitionIncident::class)->handle($incident, User::factory()->create(), IncidentStatus::Acknowledged);

    expect($incident->fresh()->sla_breached)->toBeTrue()
        ->and(IncidentSlaEvent::sole())
        ->metric->toBe(SlaMetric::Acknowledge)
        ->type->toBe(SlaEventType::Breached);
});
