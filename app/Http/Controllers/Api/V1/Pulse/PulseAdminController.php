<?php

namespace App\Http\Controllers\Api\V1\Pulse;

use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\PulseResponseResource;
use App\Models\PulseResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The owner dashboard. A small internal survey, so the whole set is returned at once and
 * the browser computes the KPI, matrix and export views from it.
 */
class PulseAdminController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize(PermissionKey::SurveyManage->value);

        return PulseResponseResource::collection(
            PulseResponse::query()
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(),
        );
    }
}
