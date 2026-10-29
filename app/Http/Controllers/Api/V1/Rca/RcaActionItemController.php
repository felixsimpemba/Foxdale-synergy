<?php

namespace App\Http\Controllers\Api\V1\Rca;

use App\Enums\ActionItemStatus;
use App\Enums\PermissionKey;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rca\CompleteActionItemRequest;
use App\Http\Requests\Rca\SaveActionItemRequest;
use App\Http\Resources\RcaActionItemResource;
use App\Models\Rca;
use App\Models\RcaActionItem;
use App\Services\RcaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrective and preventive actions that follow from an RCA.
 */
class RcaActionItemController extends Controller
{
    /**
     * @var list<string>
     */
    private const RELATIONS = ['owner', 'completer', 'rca.incident'];

    public function __construct(private RcaService $rcas) {}

    /**
     * All action items the user may see: on incidents visible to them, plus their own.
     * Filters: status (comma-separated), owner_id ("me" for the current user), overdue,
     * team_id (the incident's team). meta.counts has open, overdue and mine totals.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'owner_id' => ['nullable', 'string', 'regex:/^(me|\d+)$/'],
            'overdue' => ['nullable', 'boolean'],
            'team_id' => ['nullable', 'integer'],
        ]);

        $statuses = array_values(array_intersect(
            explode(',', (string) ($filters['status'] ?? '')),
            array_column(ActionItemStatus::cases(), 'value'),
        ));
        $ownerId = ($filters['owner_id'] ?? null) === 'me' ? $user->id : ($filters['owner_id'] ?? null);

        $visible = fn (): Builder => RcaActionItem::query()->where(fn (Builder $query) => $query
            ->where('owner_id', $user->id)
            ->orWhereHas('rca.incident', fn (Builder $incident) => $incident->visibleTo($user)));

        $query = $visible()
            ->with(self::RELATIONS)
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->when($ownerId !== null, fn (Builder $query) => $query->where('owner_id', $ownerId))
            ->when($request->boolean('overdue'), fn (Builder $query) => $query->overdue())
            ->when(filled($filters['team_id'] ?? null), fn (Builder $query) => $query->whereHas('rca.incident', fn (Builder $incident) => $incident->where('team_id', $filters['team_id'])))
            ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 0 else 1 end")
            ->orderByRaw('due_date is null, due_date')
            ->orderBy('id');

        return RcaActionItemResource::collection($query->paginate($this->perPage($request)))->additional(['meta' => ['counts' => [
            'open' => $visible()->unfinished()->count(),
            'overdue' => $visible()->overdue()->count(),
            'mine' => RcaActionItem::query()->where('owner_id', $user->id)->unfinished()->count(),
        ]]]);
    }

    public function indexForRca(Rca $rca): AnonymousResourceCollection
    {
        Gate::authorize('view', $rca);

        return RcaActionItemResource::collection($rca->actionItems()->with(self::RELATIONS)->get());
    }

    public function store(SaveActionItemRequest $request, Rca $rca): JsonResponse
    {
        Gate::authorize('update', $rca);

        $item = $this->rcas->addActionItem($rca, $request->validated());

        return $this->present($item)->response()->setStatusCode(201);
    }

    /**
     * Owners without rca.write may only move the status and write the note.
     */
    public function update(SaveActionItemRequest $request, RcaActionItem $actionItem): RcaActionItemResource
    {
        Gate::authorize('update', $actionItem);

        $data = $request->validated();

        if (! $request->user()->can(PermissionKey::RcaWrite->value)) {
            $notAllowed = array_diff(array_keys($data), ['status', 'note']);

            if ($notAllowed !== []) {
                throw ValidationException::withMessages(
                    array_fill_keys($notAllowed, ['Only the status and note can be changed by the owner.']),
                );
            }
        }

        return $this->present($this->rcas->updateActionItem($actionItem, $request->user(), $data));
    }

    public function complete(CompleteActionItemRequest $request, RcaActionItem $actionItem): RcaActionItemResource
    {
        Gate::authorize('update', $actionItem);

        return $this->present($this->rcas->completeActionItem($actionItem, $request->user(), $request->validated('note')));
    }

    /**
     * Remove an item added by mistake. Only while the RCA can still be edited; once it is in
     * review or approved, mark the item "won't do" with a reason instead.
     */
    public function destroy(RcaActionItem $actionItem): Response
    {
        $rca = Rca::query()->findOrFail($actionItem->rca_id);
        Gate::authorize('update', $rca);

        if (! $rca->status->isEditable()) {
            throw new ApiException('Action items of an RCA in review or approved cannot be deleted. Mark it "won\'t do" with a reason instead.', 'rca_locked', 409);
        }

        $actionItem->delete();

        return response()->noContent();
    }

    private function present(RcaActionItem $item): RcaActionItemResource
    {
        return new RcaActionItemResource(RcaActionItem::query()->with(self::RELATIONS)->findOrFail($item->id));
    }
}
