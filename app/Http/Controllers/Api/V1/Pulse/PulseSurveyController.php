<?php

namespace App\Http\Controllers\Api\V1\Pulse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pulse\StorePulseResponseRequest;
use App\Http\Requests\Pulse\UpdatePulseResponseRequest;
use App\Http\Resources\PulseResponseResource;
use App\Models\PulseResponse;
use App\Services\Notifications\NotificationService;
use App\Services\Pulse\PulseSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public Department Pulse form. Creating and reading a single response needs no account;
 * editing needs the private token issued at creation.
 */
class PulseSurveyController extends Controller
{
    public function __construct(private readonly PulseSurvey $survey) {}

    public function config(): JsonResponse
    {
        return response()->json(['data' => $this->survey->config()]);
    }

    public function store(StorePulseResponseRequest $request, NotificationService $notifications): JsonResponse
    {
        $token = $this->survey->newEditToken();

        $response = PulseResponse::create([
            'department' => (string) $request->validated('department'),
            'name' => (string) $request->validated('name'),
            'role' => (string) $request->validated('role'),
            'answers' => $request->answers(),
            'edit_token_hash' => PulseResponse::hashToken($token),
        ]);

        $this->survey->notifyAdmins($response, $notifications);

        return response()->json([
            'data' => [
                'response' => new PulseResponseResource($response),
                'edit_token' => $token,
            ],
        ], 201);
    }

    public function show(Request $request, PulseResponse $pulseResponse): PulseResponseResource
    {
        $this->assertEditToken($request, $pulseResponse);

        return new PulseResponseResource($pulseResponse);
    }

    public function update(UpdatePulseResponseRequest $request, PulseResponse $pulseResponse): JsonResponse
    {
        // Rotate the token on every save, so an old copy of the link stops working.
        $token = $this->survey->newEditToken();

        $pulseResponse->update([
            'department' => (string) $request->validated('department'),
            'name' => (string) $request->validated('name'),
            'role' => (string) $request->validated('role'),
            'answers' => $request->answers(),
            'edit_token_hash' => PulseResponse::hashToken($token),
        ]);

        return response()->json([
            'data' => [
                'response' => new PulseResponseResource($pulseResponse),
                'edit_token' => $token,
            ],
        ]);
    }

    private function assertEditToken(Request $request, PulseResponse $response): void
    {
        abort_unless($response->hasEditToken((string) $request->header('X-Pulse-Token', '')), 404);
    }
}
