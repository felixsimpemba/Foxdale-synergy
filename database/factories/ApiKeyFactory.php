<?php

namespace Database\Factories;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'source' => fake()->randomElement(['zabbix', 'grafana', 'uptime']),
            'key_prefix' => substr($plainKey = 'fxk_'.Str::random(40), 0, 12),
            'key_hash' => ApiKey::hashKey($plainKey),
            'scopes' => ['incidents:create', 'incidents:update'],
        ];
    }
}
