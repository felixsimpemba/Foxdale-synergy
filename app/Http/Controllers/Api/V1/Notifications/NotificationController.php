<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppNotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app bell: recent entries, unread count, and marking them read. Personal: a user
 * only ever sees their own.
 */
class NotificationController extends Controller
{
    /**
     * ?unread=1 limits it to unread entries. meta.unread is the badge count.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['unread' => ['nullable', 'boolean']]);

        $query = $request->user()->notifications()->getQuery();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return AppNotificationResource::collection($query->latest()->paginate($this->perPage($request, 20)))
            ->additional(['meta' => [
                'unread' => $request->user()->unreadNotifications()->count(),
            ]]);
    }

    public function read(Request $request, string $notification): AppNotificationResource
    {
        $entry = $this->mine($request, $notification);
        $entry->markAsRead();

        return new AppNotificationResource($entry);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }

    public function destroy(Request $request, string $notification): Response
    {
        $this->mine($request, $notification)->delete();

        return response()->noContent();
    }

    private function mine(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification|null $entry */
        $entry = $request->user()->notifications()->whereKey($id)->first();

        abort_if($entry === null, 404);

        return $entry;
    }
}
