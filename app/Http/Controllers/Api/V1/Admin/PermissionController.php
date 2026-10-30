<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        return response()->json(['data' => array_map(fn (PermissionKey $key): array => [
            'key' => $key->value,
            'description' => $key->description(),
        ], PermissionKey::cases())]);
    }
}
