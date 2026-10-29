<?php

use App\Enums\FixType;
use App\Enums\IncidentStatus;
use App\Enums\RcaStatus;
use App\Enums\RoleName;
use App\Models\Incident;
use App\Models\IncidentResolution;
use App\Models\Rca;

function pastFix(string $title, string $summary, array $resolution = [], array $incident = []): Incident
{
    $resolved = Incident::factory()->inStatus(IncidentStatus::Resolved)->create(['title' => $title, ...$incident]);
    IncidentResolution::factory()->for($resolved)->create(['summary' => $summary, ...$resolution]);

    return $resolved;
}

it('finds past fixes by words in the resolution and ranks the best match first', function () {
    actingAsRole(RoleName::Noc);
    $best = pastFix('Deposits failing on payment gateway', 'RabbitMQ consumer stuck after node restart', ['permanent_fix' => 'Consumer reconnect with backoff']);
    $partial = pastFix('Slow reports', 'Consumer lag on the reporting replica');
    pastFix('Login page down', 'Expired TLS certificate renewed');

    $results = $this->getJson('/api/v1/knowledge-base?q=consumer reconnect deposits')->assertOk()->json('data');

    expect(array_column(array_column($results, 'incident'), 'id'))->toBe([$best->id, $partial->id]);
});

it('matches word prefixes', function () {
    actingAsRole(RoleName::Noc);
    $fix = pastFix('Deposits failing', 'Callbacks timed out');

    $this->getJson('/api/v1/knowledge-base?q=deposit')
        ->assertOk()
        ->assertJsonPath('data.0.incident.id', $fix->id);
});

it('shows a reporter published entries and their own incidents only', function () {
    $reporter = actingAsRole(RoleName::Reporter);
    $published = pastFix('Gateway timeout', 'Increased gateway timeout', ['is_published' => true]);
    $own = pastFix('Gateway timeout for me', 'Gateway restarted', incident: ['reporter_id' => $reporter->id]);
    pastFix('Gateway timeout elsewhere', 'Gateway restarted again');

    $ids = collect($this->getJson('/api/v1/knowledge-base?q=gateway')->assertOk()->json('data'))
        ->pluck('incident.id')->sort()->values()->all();

    expect($ids)->toBe([$published->id, $own->id]);
});

it('filters by fix type and leaves out open incidents', function () {
    actingAsRole(RoleName::Noc);
    $rollback = pastFix('Bad release', 'Rolled back release 4.2', ['fix_type' => FixType::Rollback]);
    pastFix('Bad config', 'Reverted release config', ['fix_type' => FixType::ConfigChange]);
    $open = Incident::factory()->inStatus(IncidentStatus::Investigating)->create(['title' => 'Bad release again']);
    IncidentResolution::factory()->for($open)->create(['summary' => 'Release rollback planned', 'fix_type' => FixType::Rollback]);

    $this->getJson('/api/v1/knowledge-base?q=release&fix_type=rollback')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.incident.id', $rollback->id);
});

it('treats search operators in the query as plain text', function () {
    actingAsRole(RoleName::Noc);
    $fix = pastFix('Deposits failing', 'Callbacks timed out');

    $this->getJson('/api/v1/knowledge-base?q='.urlencode('+deposits -"callbacks (*'))
        ->assertOk()
        ->assertJsonPath('data.0.incident.id', $fix->id);
});

it('finds past fixes by words in their approved RCA, but not in a draft', function () {
    actingAsRole(RoleName::Noc);
    $approved = pastFix('Payments down', 'Restarted the consumer');
    Rca::factory()->inStatus(RcaStatus::Approved)->create(['incident_id' => $approved->id, 'root_cause' => 'Certificate rotation script skipped the broker']);
    $draft = pastFix('Payments down again', 'Restarted the consumer');
    Rca::factory()->create(['incident_id' => $draft->id, 'root_cause' => 'Certificate rotation script failed']);

    $this->getJson('/api/v1/knowledge-base?q=rotation broker')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.incident.id', $approved->id)
        ->assertJsonPath('data.0.rca.root_cause', 'Certificate rotation script skipped the broker');
});
