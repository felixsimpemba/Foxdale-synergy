<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StorePushSubscriptionRequest;
use App\Http\Resources\PushSubscriptionResource;
use App\Models\PushSubscription;
use App\Services\Notifications\PushOutcome;
use App\Services\Notifications\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Browsers ask for push permission themselves; the server only stores the subscription and
 * sends messages to it.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    /**
     * What the browser needs to subscribe. enabled=false means the server has no VAPID keys.
     */
    public function config(): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $this->push->isAvailable(),
            'public_key' => $this->push->publicKey(),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return PushSubscriptionResource::collection(
            $request->user()->pushSubscriptions()->latest('id')->get(),
        );
    }

    /**
     * Subscribing twice from the same browser updates the first row. A shared computer
     * whose browser endpoint now belongs to another signed-in user moves to that user.
     */
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint' => $request->validated('endpoint')],
            [
                'user_id' => $request->user()->id,
                'public_key' => $request->validated('keys.p256dh'),
                'auth_token' => $request->validated('keys.auth'),
                'content_encoding' => $request->validated('content_encoding') ?? 'aesgcm',
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 150),
            ],
        );

        return (new PushSubscriptionResource($subscription))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, PushSubscription $pushSubscription): Response
    {
        abort_unless($pushSubscription->user_id === $request->user()->id, 404);

        $pushSubscription->delete();

        return response()->noContent();
    }

    /**
     * Remove a browser by the endpoint the browser itself knows, which is how it cleans up
     * after unsubscribe().
     */
    public function destroyByEndpoint(Request $request): Response
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint', $request->string('endpoint'))
            ->delete();

        return response()->noContent();
    }

    /**
     * Sends one test message to this browser, synchronously, so the person sees at once
     * whether it worked.
     */
    public function test(Request $request, PushSubscription $pushSubscription): JsonResponse
    {
        abort_unless($pushSubscription->user_id === $request->user()->id, 404);

        $result = $this->push->sendTo($pushSubscription, [
            'title' => 'Notifications are on',
            'body' => 'You will get incident alerts in this browser.',
            'url' => config('incidents.frontend_url').'/notifications',
            'tag' => 'test',
        ]);

        if ($result->outcome === PushOutcome::Expired) {
            $pushSubscription->delete();
        }

        return response()->json([
            'data' => ['sent' => $result->outcome === PushOutcome::Sent, 'error' => $result->error],
        ]);
    }
}
