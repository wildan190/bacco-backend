<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Target\StoreTargetRequest;
use App\Http\Resources\SalesTargetResource;
use App\Services\RevenueKpiService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected RevenueKpiService $revenueKpiService
    ) {}

    /**
     * Admin dashboard: Total revenue & per-sales KPI breakdown.
     */
    public function adminRevenue(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', Carbon::now()->month);
        $year = (int) $request->query('year', Carbon::now()->year);

        $summary = $this->revenueKpiService->getAdminRevenueSummary($month, $year);
        $salesBreakdown = $this->revenueKpiService->getSalesRevenueBreakdown($month, $year);

        return response()->json([
            'summary' => $summary,
            'sales_breakdown' => $salesBreakdown,
        ]);
    }

    /**
     * Sales dashboard: My personal target and KPI progress.
     */
    public function salesTarget(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', Carbon::now()->month);
        $year = (int) $request->query('year', Carbon::now()->year);

        $kpi = $this->revenueKpiService->getSalesPersonalKpi($request->user(), $month, $year);

        return response()->json([
            'kpi' => $kpi,
        ]);
    }

    /**
     * Admin: Set or update sales target.
     */
    public function saveTarget(StoreTargetRequest $request): JsonResponse
    {
        $target = $this->revenueKpiService->saveTarget($request->validated());

        return response()->json([
            'message' => 'Target sales berhasil disimpan.',
            'target' => new SalesTargetResource($target),
        ]);
    }

    /**
     * Admin: List all targets for a given period.
     */
    public function listTargets(Request $request): JsonResponse
    {
        $month = (int) $request->query('month', Carbon::now()->month);
        $year = (int) $request->query('year', Carbon::now()->year);

        $targets = $this->revenueKpiService->getTargets($month, $year);

        return response()->json([
            'targets' => $targets,
        ]);
    }
}
