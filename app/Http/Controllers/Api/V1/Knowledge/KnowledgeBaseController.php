<?php

namespace App\Http\Controllers\Api\V1\Knowledge;

use App\Enums\IncidentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\SearchKnowledgeBaseRequest;
use App\Http\Resources\KnowledgeBaseEntryResource;
use App\Models\Incident;
use App\Services\KnowledgeBaseService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Past fixes, searchable from the reporting form and by responders.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(private KnowledgeBaseService $knowledgeBase) {}

    /**
     * Filters: q, service_id, category_id, fix_type, published.
     */
    public function index(SearchKnowledgeBaseRequest $request): AnonymousResourceCollection
    {
        return KnowledgeBaseEntryResource::collection(
            $this->knowledgeBase->search($request->user(), $request->validated(), $this->perPage($request)),
        );
    }

    /**
     * One past incident with its fix; documents are included only for people who can see
     * the incident itself.
     */
    public function show(Request $request, Incident $incident): KnowledgeBaseEntryResource
    {
        $entry = $this->knowledgeBase->find($request->user(), $incident->id) ?? abort(404);
        $entry->load('resolution.publisher', 'service', 'category', 'approvedRca.actionItems');

        if ($entry->isVisibleTo($request->user())) {
            $entry->load('documents.uploader');
        }

        return new KnowledgeBaseEntryResource($entry);
    }

    /**
     * Mark the resolution as a reviewed article, readable by everyone.
     */
    public function publish(Request $request, Incident $incident): KnowledgeBaseEntryResource
    {
        return $this->setPublished($request, $incident, true);
    }

    public function unpublish(Request $request, Incident $incident): KnowledgeBaseEntryResource
    {
        return $this->setPublished($request, $incident, false);
    }

    private function setPublished(Request $request, Incident $incident, bool $published): KnowledgeBaseEntryResource
    {
        Gate::authorize('publishKnowledge', $incident);

        $resolution = $incident->resolution;

        if ($resolution === null || ! in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            throw new ApiException('Only resolved incidents with a written resolution can be published.', 'not_resolved');
        }

        $resolution->forceFill([
            'is_published' => $published,
            'published_at' => $published ? now() : null,
            'published_by' => $published ? $request->user()->id : null,
        ])->save();

        return new KnowledgeBaseEntryResource($incident->load('resolution.publisher', 'service', 'category'));
    }
}
