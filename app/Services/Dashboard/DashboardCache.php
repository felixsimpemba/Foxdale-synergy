<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * KPI results are cached for 60 seconds per user and filter set. Any change to an incident,
 * time entry or SLA event moves the version, so the next request recomputes.
 */
class DashboardCache
{
    private const VERSION_KEY = 'dashboard.version';

    private const TTL_SECONDS = 60;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(User $user, string $name, string $filtersKey, Closure $compute): mixed
    {
        $version = Cache::rememberForever(self::VERSION_KEY, fn (): string => Str::random(8));

        return Cache::remember(
            'dashboard:'.$version.':'.$user->id.':'.$name.':'.md5($filtersKey),
            self::TTL_SECONDS,
            $compute,
        );
    }

    public function bust(): void
    {
        Cache::forever(self::VERSION_KEY, Str::random(8));
    }
}
