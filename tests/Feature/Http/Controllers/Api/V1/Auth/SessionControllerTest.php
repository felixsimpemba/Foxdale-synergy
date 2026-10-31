<?php

use App\Models\AuditLog;
use App\Models\User;

describe('index', function () {
    it('lists signed-in browser sessions and marks the current one', function () {
        $user = User::factory()->create();
        $current = $user->createToken('session:web', ['*']);
        $user->createToken('session:phone', ['*']);
        $user->createToken('script', ['mcp:read']);

        $response = $this->withToken($current->plainTextToken)->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $sessions = collect($response->json('data'));
        $currentSession = $sessions->firstWhere('is_current', true);

        expect($currentSession['device'])->toBe('web')
            ->and($sessions->pluck('device')->sort()->values()->all())->toBe(['phone', 'web']);
    });
});

describe('destroy', function () {
    it('signs out one other device', function () {
        $user = User::factory()->create();
        $current = $user->createToken('session:web', ['*']);
        $other = $user->createToken('session:phone', ['*']);

        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$other->accessToken->id}")
            ->assertNoContent();

        expect($other->accessToken->fresh())->toBeNull()
            ->and($current->accessToken->fresh())->not->toBeNull();
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'session.revoked']);
    });

    it('does not let the current session revoke itself', function () {
        $user = User::factory()->create();
        $current = $user->createToken('session:web', ['*']);

        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$current->accessToken->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'current_session');

        expect($current->accessToken->fresh())->not->toBeNull();
    });

    it("cannot revoke another person's session", function () {
        $user = User::factory()->create();
        $current = $user->createToken('session:web', ['*']);
        $stranger = User::factory()->create()->createToken('session:web', ['*']);

        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$stranger->accessToken->id}")
            ->assertNotFound();

        expect($stranger->accessToken->fresh())->not->toBeNull();
    });
});

describe('destroy others', function () {
    it('signs out every other browser session but keeps personal tokens', function () {
        $user = User::factory()->create();
        $current = $user->createToken('session:web', ['*']);
        $user->createToken('session:phone', ['*']);
        $personal = $user->createToken('script', ['mcp:read']);

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonPath('revoked', 1);

        expect($user->tokens()->pluck('id')->sort()->values()->all())->toBe(
            [$current->accessToken->id, $personal->accessToken->id],
        );
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'session.revoked_others']);
    });
});
