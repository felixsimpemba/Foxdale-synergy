<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runtime settings editable by admins. Defaults come from config('incidents.settings');
 * stored rows override them.
 */
class SettingsService
{
    private const CACHE_KEY = 'incidents.settings';

    public function __construct(private AuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn (): array => Setting::query()->pluck('value', 'key')->all());

        return array_merge($this->defaults(), array_intersect_key($stored, $this->defaults()));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values  keys already validated against defaults()
     */
    public function update(array $values): void
    {
        $before = array_intersect_key($this->all(), $values);

        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        Cache::forget(self::CACHE_KEY);

        $this->audit->record('settings.updated', changes: ['before' => $before, 'after' => $values]);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return config('incidents.settings');
    }
}
