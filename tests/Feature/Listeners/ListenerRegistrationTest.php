<?php

use App\Events\IncidentAssigned;
use App\Events\IncidentCreated;
use App\Events\IncidentStatusChanged;
use App\Events\SlaBreached;
use App\Events\SlaWarningRaised;
use Illuminate\Support\Facades\Event;

/*
 * Listeners in app/Listeners are discovered automatically. Registering them again in a
 * provider sends every notification twice, so guard the count.
 */
it('registers each notification listener exactly once', function (string $event) {
    expect(Event::getListeners($event))->toHaveCount(1);
})->with([
    IncidentCreated::class,
    IncidentAssigned::class,
    IncidentStatusChanged::class,
    SlaWarningRaised::class,
    SlaBreached::class,
]);
