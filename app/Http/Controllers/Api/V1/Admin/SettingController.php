<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(): JsonResponse
    {
        Gate::authorize('viewAny', Setting::class);

        return response()->json(['data' => $this->settings->all()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        Gate::authorize('update', Setting::class);

        $this->settings->update($request->validated('settings'));

        return response()->json(['data' => $this->settings->all()]);
    }
}
