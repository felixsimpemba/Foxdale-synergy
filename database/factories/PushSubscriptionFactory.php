<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(40),
            'public_key' => Str::random(87),
            'auth_token' => Str::random(22),
            'content_encoding' => 'aesgcm',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/141.0 Safari/537.36',
        ];
    }

    public function foriPhone(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1',
        ]);
    }
}
