<?php

namespace App\Http\Controllers\Api\V1\Routing;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\OnCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnCallController extends Controller
{
    /**
     * GET /on-call/now?team_id=: who is on call for a team right now (null if nobody).
     */
    public function now(Request $request, OnCallService $onCall): JsonResponse
    {
        $request->validate(['team_id' => ['required', 'integer', Rule::exists('teams', 'id')]]);

        $team = Team::findOrFail($request->integer('team_id'));
        $shift = $onCall->currentShift($team);

        return response()->json(['data' => [
            'team' => ['id' => $team->id, 'name' => $team->name],
            'user' => $shift === null ? null : ['id' => $shift->user->id, 'name' => $shift->user->name],
            'shift' => $shift === null ? null : [
                'id' => $shift->id,
                'schedule' => ['id' => $shift->schedule->id, 'name' => $shift->schedule->name],
                'starts_at' => $shift->starts_at->toIso8601ZuluString(),
                'ends_at' => $shift->ends_at->toIso8601ZuluString(),
                'is_override' => $shift->is_override,
            ],
        ]]);
    }
}
