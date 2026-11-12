<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Enums\NotificationEvent;
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
    /** The events collected by the mentions inbox. */
    private const MENTION_EVENTS = [
        NotificationEvent::IncidentMention->value,
        NotificationEvent::TaskMention->value,
    ];

    /**
     * ?unread=1 limits it to unread entries, ?mentions=1 to mentions. meta.unread is the
     * bell badge; meta.mentions_unread is the mentions inbox badge.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'unread' => ['nullable', 'boolean'],
            'mentions' => ['nullable', 'boolean'],
        ]);

        $query = $request->user()->notifications()->getQuery();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        if ($request->boolean('mentions')) {
            $query->whereIn('data->event', self::MENTION_EVENTS);
        }

        return AppNotificationResource::collection($query->latest()->paginate($this->perPage($request, 20)))
            ->additional(['meta' => [
                'unread' => $request->user()->unreadNotifications()->count(),
                'mentions_unread' => $request->user()->unreadNotifications()
                    ->whereIn('data->event', self::MENTION_EVENTS)
                    ->count(),
            ]]);
    }

    public function read(Request $request, string $notification): AppNotificationResource
    {
        $entry = $this->mine($request, $notification);
        $entry->markAsRead();

        return new AppNotificationResource($entry);
    }

    /**
     * ?mentions=1 marks only mentions read, which is what the mentions inbox uses.
     */
    public function readAll(Request $request): JsonResponse
    {
        $request->validate(['mentions' => ['nullable', 'boolean']]);

        $query = $request->user()->unreadNotifications();

        if ($request->boolean('mentions')) {
            $query->whereIn('data->event', self::MENTION_EVENTS);
        }

        $query->update(['read_at' => now()]);

        return response()->json(['data' => [
            'unread' => $request->user()->unreadNotifications()->count(),
        ]]);
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
