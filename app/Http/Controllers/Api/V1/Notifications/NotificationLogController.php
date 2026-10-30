<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The delivery log (specification section 12): what was sent, to whom, on which channel, and
 * whether it worked. For admins with audit.view.
 */
class NotificationLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'event' => ['nullable', Rule::enum(NotificationEvent::class)],
            'channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            'status' => ['nullable', Rule::enum(NotificationDeliveryStatus::class)],
            'user_id' => ['nullable', 'integer'],
        ]);

        $logs = NotificationLog::query()
            ->with('user')
            ->when(filled($filters['event'] ?? null), fn (Builder $query) => $query->where('event', $filters['event']))
            ->when(filled($filters['channel'] ?? null), fn (Builder $query) => $query->where('channel', $filters['channel']))
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(filled($filters['user_id'] ?? null), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->latest('id')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $logs->getCollection()->map(fn (NotificationLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'channel' => $log->channel,
                'status' => $log->status,
                'title' => $log->title,
                'error' => $log->error,
                'user' => $log->user === null ? null : ['id' => $log->user->id, 'name' => $log->user->name],
                'sent_at' => $log->sent_at?->toIso8601ZuluString(),
                'created_at' => $log->created_at?->toIso8601ZuluString(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }
}
