<?php

use App\Enums\RoleName;
use App\Models\AuditLog;

// Setting keys contain dots, so values are read from json('data') rather than assertJsonPath().

it('returns the defaults when nothing is stored', function () {
    actingAsRole(RoleName::Admin);

    $settings = $this->getJson('/api/v1/admin/settings')->assertOk()->json('data');

    expect($settings)
        ->toHaveKey('uploads.max_kb', 25600)
        ->toHaveKey('rca.due_business_days', 5);
});

it('stores overrides and audits the before and after values', function () {
    actingAsRole(RoleName::Admin);

    $updated = $this->putJson('/api/v1/admin/settings', ['settings' => ['ai.enabled' => true, 'uploads.max_kb' => 10240]])
        ->assertOk()
        ->json('data');

    expect($updated)->toHaveKey('ai.enabled', true)->toHaveKey('uploads.max_kb', 10240)
        ->and($this->getJson('/api/v1/admin/settings')->json('data'))->toHaveKey('uploads.max_kb', 10240);

    $log = AuditLog::firstWhere('action', 'settings.updated');
    expect($log->changes['before'])->toEqual(['ai.enabled' => false, 'uploads.max_kb' => 25600]);
});

it('rejects an unknown key or a value of the wrong type', function (array $settings, string $message) {
    actingAsRole(RoleName::Admin);

    $this->putJson('/api/v1/admin/settings', ['settings' => $settings])
        ->assertUnprocessable()
        ->assertJsonPath('errors.settings.0', $message);
})->with([
    'unknown key' => [['ai.secret_mode' => true], 'Unknown setting [ai.secret_mode].'],
    'wrong type' => [['ai.enabled' => 'yes'], 'The setting [ai.enabled] must be of type boolean.'],
    'bad timezone' => [['display_timezone' => 'Mars/Olympus'], 'The setting [display_timezone] must be a valid timezone.'],
]);

it('returns 403 for an incident manager', function () {
    actingAsRole(RoleName::IncidentManager);

    $this->getJson('/api/v1/admin/settings')->assertForbidden();
});
