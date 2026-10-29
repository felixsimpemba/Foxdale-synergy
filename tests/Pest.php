<?php

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

// InnoDB only indexes FULLTEXT changes on commit, so search tests cannot run inside a
// rolled-back transaction. These truncate the tables before each test instead.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Integration');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * A user holding one of the default roles. Roles that require 2FA get it set up, so the
 * user can reach every endpoint their permissions allow.
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(RoleName $role, array $attributes = []): User
{
    $factory = User::factory()->withRole($role);

    if ($role->requiresTwoFactor()) {
        $factory = $factory->withTwoFactor();
    }

    return $factory->create($attributes);
}

/**
 * Authenticate as a new user with the given role, using a full-ability session token.
 */
function actingAsRole(RoleName $role): User
{
    $user = userWithRole($role);
    Sanctum::actingAs($user, ['*']);

    return $user;
}

function currentTotpCode(User $user): string
{
    return (new Google2FA)->getCurrentOtp($user->two_factor_secret);
}

/**
 * The Have I Been Pwned range API used by Password::uncompromised(). An empty body means
 * the password has not appeared in a breach.
 */
function fakeBreachedPasswordCheck(string $body = ''): void
{
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response($body)]);
}
