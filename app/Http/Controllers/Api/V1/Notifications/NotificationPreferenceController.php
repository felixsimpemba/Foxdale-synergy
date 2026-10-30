<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's own notification settings (specification section 12).
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->for($request->user())]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->preferences->store($request->user(), $request->validated())]);
    }
}
