<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

describe('store', function () {
    it('creates a scoped personal token and shows the plain token once', function () {
        $this->freezeTime();
        $user = User::factory()->create();
        $session = $user->createToken('session:web', ['*'])->plainTextToken;

        $response = $this->withToken($session)->postJson('/api/v1/auth/tokens', [
            'name' => 'Claude Code',
            'abilities' => ['mcp:read', 'mcp:write'],
            'expires_in_days' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Claude Code')
            ->assertJsonPath('data.abilities', ['mcp:read', 'mcp:write'])
            ->assertJsonPath('data.expires_at', now()->addDays(30)->toIso8601ZuluString());

        $token = PersonalAccessToken::findToken($response->json('token'));
        expect($token->tokenable->is($user))->toBeTrue();
    });

    it('defaults personal tokens to 90 days', function () {
        $this->freezeTime();
        $user = User::factory()->create();

        $this->withToken($user->createToken('session:web', ['*'])->plainTextToken)
            ->postJson('/api/v1/auth/tokens', ['name' => 'script', 'abilities' => ['incidents:read']])
            ->assertCreated()
            ->assertJsonPath('data.expires_at', now()->addDays(90)->toIso8601ZuluString());
    });

    it('returns 422 for the wildcard ability or an unknown ability', function (string $ability) {
        $user = User::factory()->create();

        $this->withToken($user->createToken('session:web', ['*'])->plainTextToken)
            ->postJson('/api/v1/auth/tokens', ['name' => 'script', 'abilities' => [$ability]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities.0');
    })->with(['*', 'admin:all']);

    it('returns 403 when a personal token tries to create another token', function () {
        $user = User::factory()->create();
        $personal = $user->createToken('script', ['mcp:read', 'mcp:write'])->plainTextToken;

        $this->withToken($personal)
            ->postJson('/api/v1/auth/tokens', ['name' => 'escalated', 'abilities' => ['mcp:write']])
            ->assertForbidden();

        expect($user->tokens()->count())->toBe(1);
    });
});

describe('index and destroy', function () {
    it('lists only the user\'s own tokens and marks the current one', function () {
        $user = User::factory()->create();
        $session = $user->createToken('session:web', ['*']);
        $user->createToken('script', ['mcp:read']);
        User::factory()->create()->createToken('someone-else', ['mcp:read']);

        $this->withToken($session->plainTextToken)
            ->getJson('/api/v1/auth/tokens')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $session->accessToken->id)
            ->assertJsonPath('data.1.is_current', true);
    });

    it('revokes one of the user\'s tokens', function () {
        $user = User::factory()->create();
        $session = $user->createToken('session:web', ['*'])->plainTextToken;
        $script = $user->createToken('script', ['mcp:read'])->accessToken;

        $this->withToken($session)->deleteJson("/api/v1/auth/tokens/{$script->id}")->assertNoContent();

        expect(PersonalAccessToken::find($script->id))->toBeNull();
    });

    it('returns 404 for another user\'s token', function () {
        $user = User::factory()->create();
        $other = User::factory()->create()->createToken('script', ['mcp:read'])->accessToken;

        $this->withToken($user->createToken('session:web', ['*'])->plainTextToken)
            ->deleteJson("/api/v1/auth/tokens/{$other->id}")
            ->assertNotFound();

        expect(PersonalAccessToken::find($other->id))->not->toBeNull();
    });
});
