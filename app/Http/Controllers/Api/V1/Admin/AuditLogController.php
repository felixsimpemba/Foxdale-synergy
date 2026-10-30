<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    /**
     * Filters: user_id, entity_type, entity_id, action (prefix match, e.g. "auth."), source, from, to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', AuditLog::class);

        $request->validate([
            'user_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'entity_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:16'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->with('user')
            ->when($request->filled('user_id'), fn (Builder $query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('entity_type'), fn (Builder $query) => $query->where('entity_type', $request->string('entity_type')))
            ->when($request->filled('entity_id'), fn (Builder $query) => $query->where('entity_id', $request->integer('entity_id')))
            ->when($request->filled('action'), fn (Builder $query) => $query->where('action', 'like', $request->string('action')->replace(['%', '_'], ['\%', '\_']).'%'))
            ->when($request->filled('source'), fn (Builder $query) => $query->where('source', $request->string('source')))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 50));

        return AuditLogResource::collection($logs);
    }
}
