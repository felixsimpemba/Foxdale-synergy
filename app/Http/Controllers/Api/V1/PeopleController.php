<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Active people and their teams, for pickers (assignee when reporting, action item owners).
 * Only for staff who may assign incidents or write RCAs: reporters cannot list everyone.
 */
class PeopleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->canAny([PermissionKey::IncidentAssign->value, PermissionKey::RcaWrite->value]), 403);

        $people = User::query()
            ->where('is_active', true)
            ->with('teams:id,name')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'teams' => $person->teams->pluck('name')->sort()->values()->all(),
            ]);

        return response()->json(['data' => $people]);
    }
}
