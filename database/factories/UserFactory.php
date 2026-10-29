<?php

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => '+2607'.fake()->numerify('########'),
            'timezone' => 'UTC',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A user who has finished TOTP setup. Read the secret back from $user->two_factor_secret.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => (new Google2FA)->generateSecretKey(),
            'two_factor_recovery_codes' => ['recovery-code-one', 'recovery-code-two'],
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Assign a role after creation. Roles come from RolesAndPermissionsSeeder.
     */
    public function withRole(RoleName $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }
}
