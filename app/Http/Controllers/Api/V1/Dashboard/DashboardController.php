<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Enums\DashboardDimension;
use App\Enums\DashboardInterval;
use App\Enums\DashboardMetric;
use App\Http\Controllers\Api\V1\Concerns\PresentsIncidents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardRequest;
use App\Http\Resources\IncidentResource;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Dashboard KPIs (specification section 9). All endpoints take from, to (default: the last
 * 30 days), team_id and service_id, and only count incidents the user may see.
 */
class DashboardController extends Controller
{
    use PresentsIncidents;

    public function __construct(private DashboardService $dashboard) {}

    public function summary(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->summary($request->user(), $request->filters())]);
    }

    /**
     * Everything the dashboard page shows, in one request: the summary, the four trends, the
     * breakdowns by service, category, team and severity, SLA compliance, workload and RCA action items. Saves
     * the page from spending its rate limit on a dozen calls. interval as for trends.
     */
    public function overview(DashboardRequest $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->filters();
        $interval = $request->enum('interval', DashboardInterval::class) ?? DashboardInterval::Day;

        return response()->json(['data' => [
            'summary' => $this->dashboard->summary($user, $filters),
            'trends' => collect(DashboardMetric::cases())->mapWithKeys(fn (DashboardMetric $metric): array => [
                $metric->value => $this->dashboard->trend($user, $filters, $metric, $interval),
            ])->all(),
            'breakdowns' => collect([DashboardDimension::Service, DashboardDimension::Category, DashboardDimension::Team, DashboardDimension::Severity])
                ->mapWithKeys(fn (DashboardDimension $by): array => [$by->value => $this->dashboard->breakdown($user, $filters, $by)])
                ->all(),
            'sla' => $this->dashboard->sla($user, $filters),
            'workload' => $this->dashboard->workload($user, $filters),
            'action_items' => $this->dashboard->actionItems($user, $filters),
        ]]);
    }

    /**
     * Open incidents, most severe then oldest first. Not cached.
     */
    public function current(DashboardRequest $request): AnonymousResourceCollection
    {
        return IncidentResource::collection(
            $this->dashboard->currentQuery($request->user(), $request->filters())
                ->with($this->incidentRelations)
                ->withTimings()
                ->limit(50)
                ->get(),
        );
    }

    /**
     * metric: opened, resolved, mtta or mttr; interval: day (default), week or month.
     */
    public function trends(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->trend(
            $request->user(),
            $request->filters(),
            $request->enum('metric', DashboardMetric::class),
            $request->enum('interval', DashboardInterval::class) ?? DashboardInterval::Day,
        )]);
    }

    /**
     * by: service, category, team, severity or source.
     */
    public function breakdown(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->breakdown(
            $request->user(),
            $request->filters(),
            $request->enum('by', DashboardDimension::class),
        )]);
    }

    public function sla(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->sla($request->user(), $request->filters())]);
    }

    public function actionItems(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->actionItems($request->user(), $request->filters())]);
    }

    public function workload(DashboardRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->workload($request->user(), $request->filters())]);
    }
}
