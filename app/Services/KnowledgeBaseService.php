<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\PermissionKey;
use App\Enums\RcaStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

/**
 * Search over past fixes: resolved and closed incidents that have a written resolution, with
 * their approved RCA when there is one.
 * Users find entries for incidents they can see, plus resolutions published as reviewed
 * articles, which everyone can read. Matching uses MySQL FULLTEXT on the incident title and
 * description the resolution summary, permanent fix and workaround, and the approved RCA's summary, root
 * cause and lessons learned, best match first.
 */
class KnowledgeBaseService
{
    /**
     * @param  array{q?: string|null, service_id?: int|null, category_id?: int|null, fix_type?: string|null, root_cause_category?: string|null, published?: bool|null}  $filters
     * @return LengthAwarePaginator<int, Incident>
     */
    public function search(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $terms = $this->booleanTerms($filters['q'] ?? null);

        $query = $this->entries($user)
            ->with(['resolution', 'service', 'category', 'approvedRca'])
            ->when(filled($filters['service_id'] ?? null), fn (Builder $query) => $query->where('incidents.service_id', $filters['service_id']))
            ->when(filled($filters['category_id'] ?? null), fn (Builder $query) => $query->where('incidents.category_id', $filters['category_id']))
            ->when(filled($filters['fix_type'] ?? null), fn (Builder $query) => $query->where('resolutions.fix_type', $filters['fix_type']))
            ->when(filled($filters['root_cause_category'] ?? null), fn (Builder $query) => $query->where('approved_rcas.root_cause_category', $filters['root_cause_category']))
            ->when($filters['published'] ?? false, fn (Builder $query) => $query->where('resolutions.is_published', true));

        if ($terms !== null) {
            $query->where(fn (Builder $query) => $query
                ->whereFullText(['incidents.title', 'incidents.description'], $terms, ['mode' => 'boolean'])
                ->orWhereFullText(['resolutions.summary', 'resolutions.permanent_fix', 'resolutions.workaround'], $terms, ['mode' => 'boolean'])
                ->orWhereFullText(['approved_rcas.summary', 'approved_rcas.root_cause', 'approved_rcas.lessons_learned'], $terms, ['mode' => 'boolean']))
                ->orderByRaw(
                    'MATCH (incidents.title, incidents.description) AGAINST (? IN BOOLEAN MODE)'
                    .' + MATCH (resolutions.summary, resolutions.permanent_fix, resolutions.workaround) AGAINST (? IN BOOLEAN MODE)'
                    .' + COALESCE(MATCH (approved_rcas.summary, approved_rcas.root_cause, approved_rcas.lessons_learned) AGAINST (? IN BOOLEAN MODE), 0) DESC',
                    [$terms, $terms, $terms],
                );
        }

        return $query->orderByDesc('incidents.resolved_at')->orderByDesc('incidents.id')->paginate($perPage);
    }

    /**
     * The entry for one incident, if the user may read it.
     */
    public function find(User $user, int $incidentId): ?Incident
    {
        return $this->entries($user)->where('incidents.id', $incidentId)->first();
    }

    /**
     * Resolved or closed incidents with a resolution that the user may read.
     *
     * @return Builder<Incident>
     */
    private function entries(User $user): Builder
    {
        $query = Incident::query()
            ->select('incidents.*')
            ->join('incident_resolutions as resolutions', 'resolutions.incident_id', '=', 'incidents.id')
            // Approved RCAs feed the knowledge base (specification section 8).
            ->leftJoin('rcas as approved_rcas', fn (JoinClause $join) => $join
                ->on('approved_rcas.incident_id', '=', 'incidents.id')
                ->where('approved_rcas.status', RcaStatus::Approved->value))
            ->whereIn('incidents.status', [IncidentStatus::Resolved->value, IncidentStatus::Closed->value]);

        // visibleTo() adds no condition for incident.view.all, which inside an OR would be dropped.
        if ($user->can(PermissionKey::IncidentViewAll->value)) {
            return $query;
        }

        return $query->where(fn (Builder $query) => $query
            ->where('resolutions.is_published', true)
            ->orWhere(fn (Builder $visible) => $visible->visibleTo($user)));
    }

    /**
     * Turn free text into a MySQL boolean-mode query: every word as a prefix match, with the
     * operator characters removed so user input cannot change the query's meaning.
     */
    private function booleanTerms(?string $text): ?string
    {
        $words = collect(preg_split('/[^\pL\pN]+/u', Str::lower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 2)
            ->take(12)
            ->map(fn (string $word): string => $word.'*');

        return $words->isEmpty() ? null : $words->implode(' ');
    }
}
