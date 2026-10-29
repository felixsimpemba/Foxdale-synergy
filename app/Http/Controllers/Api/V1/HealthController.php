<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * GET /api/v1/health: database, cache, queue and storage status. Returns 503 if any fail.
 * Error details are logged, never returned, because the endpoint is public.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(function (): void {
                $key = 'health:'.Str::random(8);
                Cache::put($key, true, 10);
                throw_unless(Cache::pull($key), 'RuntimeException', 'Cache read failed');
            }),
            'queue' => $this->check(fn () => Queue::size()),
            'storage' => $this->check(function (): void {
                $path = 'health/'.Str::random(8);
                Storage::disk(config('incidents.uploads.disk'))->put($path, 'ok');
                Storage::disk(config('incidents.uploads.disk'))->delete($path);
            }),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
