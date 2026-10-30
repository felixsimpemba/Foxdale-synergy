<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('the people directory', function () {
    it('lists active people with their teams, sorted by name', function () {
        actingAsRole(RoleName::Noc);
        $team = Team::factory()->create(['name' => 'Payments']);
        $member = User::factory()->create(['name' => 'Zoe Phiri']);
        $team->addMember($member);
        $loner = User::factory()->create(['name' => 'Grace Mwansa']);
        User::factory()->create(['name' => 'Deactivated', 'is_active' => false]);

        $data = collect($this->getJson('/api/v1/people')->assertOk()->json('data'))->keyBy('name');

        expect($data['Grace Mwansa']['teams'])->toBe([])
            ->and($data['Zoe Phiri']['teams'])->toBe(['Payments'])
            ->and($data->keys()->all())->not->toContain('Deactivated')
            ->and($data->keys()->all())->toBe($data->keys()->sort()->values()->all())
            ->and($loner->is_active)->toBeTrue();
    });

    it('is open to RCA writers but not to reporters or responders', function (RoleName $role, int $status) {
        Sanctum::actingAs(userWithRole($role), ['*']);

        $this->getJson('/api/v1/people')->assertStatus($status);
    })->with([
        'incident manager' => [RoleName::IncidentManager, 200],
        'responder' => [RoleName::Responder, 200],
        'noc' => [RoleName::Noc, 200],
        'reporter' => [RoleName::Reporter, 403],
    ]);
});
