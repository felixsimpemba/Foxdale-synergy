<?php

use App\Enums\IncidentStatus;
use App\Enums\RcaStatus;
use App\Models\Incident;
use App\Models\IncidentResolution;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Models\Runbook;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoRcaSeeder;
use Laravel\Sanctum\Sanctum;

it('creates consistent demo incidents that the dashboard and knowledge base can use', function () {
    $this->seed(DemoDataSeeder::class);

    $incidents = Incident::all();

    expect($incidents)->toHaveCount(220)
        ->and(Service::count())->toBeGreaterThanOrEqual(9)
        ->and(Runbook::count())->toBe(6)
        ->and(IncidentResolution::where('is_published', true)->count())->toBeGreaterThan(0)
        ->and($incidents->whereNull('ref'))->toBeEmpty()
        ->and($incidents->filter(fn (Incident $incident) => $incident->status->isOpen()))->not->toBeEmpty();

    // Timestamps follow the lifecycle.
    $incidents->whereNotNull('resolved_at')->each(function (Incident $incident): void {
        expect($incident->acknowledged_at->lessThanOrEqualTo($incident->resolved_at))->toBeTrue()
            ->and($incident->reported_at->lessThanOrEqualTo($incident->acknowledged_at))->toBeTrue();
    });
    expect($incidents->where('status', IncidentStatus::Resolved)->whereNull('resolved_at'))->toBeEmpty();

    Sanctum::actingAs(User::firstWhere('email', 'peter@foxdale.local'), ['*']);

    $summary = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
    expect($summary['resolved'])->toBeGreaterThan(0)
        ->and($summary['mttr_minutes']['avg'])->toBeGreaterThan(0)
        ->and($summary['sla_compliance_pct'])->toBeGreaterThan(0);

    $this->getJson('/api/v1/knowledge-base')->assertOk()->assertJsonPath('meta.total', fn (int $total) => $total > 50);
});

it('does nothing when the demo data is already there', function () {
    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(Incident::count())->toBe(220);
});

it('adds RCAs in every state, closing the SEV1 and SEV2 incidents whose RCA is approved', function () {
    $this->seed(DemoDataSeeder::class);

    $statuses = Rca::query()->pluck('status')->map(fn (RcaStatus $status) => $status->value)->unique()->sort()->values()->all();
    expect($statuses)->toBe(['approved', 'draft', 'in_review', 'rejected']);

    // Every approved RCA meets the approval rules, and only those incidents are closed.
    Rca::query()->where('status', RcaStatus::Approved->value)->with('incident')->get()->each(function (Rca $rca): void {
        expect($rca->approvalProblems())->toBe([])
            ->and($rca->incident->status)->toBe(IncidentStatus::Closed)
            ->and($rca->reviewer_id)->not->toBe($rca->author_id);
    });
    expect(Incident::query()->where('severity', '<=', 2)->where('status', IncidentStatus::Closed->value)->whereDoesntHave('approvedRca')->exists())->toBeFalse()
        ->and(RcaActionItem::query()->overdue()->exists())->toBeTrue();

    // Running it again adds nothing.
    $count = Rca::count();
    $this->seed(DemoRcaSeeder::class);
    expect(Rca::count())->toBe($count);
});
