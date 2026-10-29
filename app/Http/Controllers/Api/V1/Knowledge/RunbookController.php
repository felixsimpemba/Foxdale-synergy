<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreRunbookRequest;
use App\Http\Resources\RunbookResource;
use App\Models\Runbook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RunbookController extends Controller
{
    /**
     * Relations every runbook response includes.
     *
     * @var list<string>
     */
    private const RELATIONS = ['service', 'category', 'creator', 'editor'];

    /**
     * Filters: service_id, category_id, q (title and body). With match=any, a runbook matches
     * the service or the category, which is how the incident page finds relevant runbooks.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Runbook::class);

        $request->validate([
            'service_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'match' => ['nullable', 'in:all,any'],
        ]);

        $serviceId = $request->filled('service_id') ? $request->integer('service_id') : null;
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;

        $query = Runbook::query()->with(self::RELATIONS);

        if ($request->input('match') === 'any' && ($serviceId !== null || $categoryId !== null)) {
            $query->where(fn (Builder $query) => $query
                ->when($serviceId !== null, fn (Builder $query) => $query->orWhere('service_id', $serviceId))
                ->when($categoryId !== null, fn (Builder $query) => $query->orWhere('category_id', $categoryId)));
        } else {
            $query->when($serviceId !== null, fn (Builder $query) => $query->where('service_id', $serviceId))
                ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId));
        }

        if ($request->filled('q')) {
            $like = '%'.addcslashes((string) $request->string('q'), '%_\\').'%';
            $query->where(fn (Builder $query) => $query->where('title', 'like', $like)->orWhere('body', 'like', $like));
        }

        return RunbookResource::collection(
            $query
                ->orderBy('title')
                ->orderBy('id')
                ->paginate($this->perPage($request)),
        );
    }

    public function show(Runbook $runbook): RunbookResource
    {
        Gate::authorize('view', $runbook);

        return new RunbookResource($runbook->load(self::RELATIONS));
    }

    public function store(StoreRunbookRequest $request): JsonResponse
    {
        Gate::authorize('create', Runbook::class);

        $runbook = Runbook::create([...$request->validated(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return (new RunbookResource($runbook->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    /**
     * Render Markdown exactly as a saved runbook would be, for the editor's preview.
     */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('create', Runbook::class);

        $validated = $request->validate(['body' => ['present', 'nullable', 'string', 'max:100000']]);

        return response()->json(['data' => ['body_html' => Runbook::renderMarkdown((string) ($validated['body'] ?? ''))]]);
    }

    public function update(StoreRunbookRequest $request, Runbook $runbook): RunbookResource
    {
        Gate::authorize('update', $runbook);

        $runbook->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return new RunbookResource($runbook->load(self::RELATIONS));
    }

    public function destroy(Runbook $runbook): Response
    {
        Gate::authorize('delete', $runbook);

        $runbook->delete();

        return response()->noContent();
    }
}
