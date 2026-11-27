<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SalesTarget;
use App\Models\StoreVisit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueKpiService
{
    /**
     * Get aggregate revenue summary for admin dashboard.
     *
     * @return array{
     *     period_month: int,
     *     period_year: int,
     *     total_revenue: float,
     *     total_orders: int,
     *     average_order_value: float,
     *     total_target_revenue: float,
     *     target_achievement_percentage: float,
     *     total_visits: int
     * }
     */
    public function getAdminRevenueSummary(int $month, int $year, ?User $admin = null): array
    {
        $scopedAdmin = ($admin && ! $admin->isSuperAdmin()) ? $admin : null;

        $salesUserIds = $scopedAdmin
            ? User::where('role', 'sales')->where('admin_id', $scopedAdmin->id)->pluck('id')->all()
            : [];

        $ordersQuery = Order::where('status', 'completed')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $targetsQuery = SalesTarget::where('period_month', $month)
            ->where('period_year', $year);

        $visitsQuery = StoreVisit::whereMonth('visited_at', $month)
            ->whereYear('visited_at', $year);

        if ($scopedAdmin) {
            $ordersQuery->whereIn('user_id', $salesUserIds);
            $targetsQuery->where(function ($q) use ($scopedAdmin, $salesUserIds) {
                $q->where('admin_id', $scopedAdmin->id)
                  ->orWhereIn('user_id', $salesUserIds);
            });
            $visitsQuery->whereIn('user_id', $salesUserIds);
        }

        $ordersStats = $ordersQuery
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_revenue, COUNT(id) as total_orders')
            ->first();

        $totalRevenue = (float) ($ordersStats->total_revenue ?? 0);
        $totalOrders = (int) ($ordersStats->total_orders ?? 0);
        $averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0.0;

        $totalTargetRevenue = (float) $targetsQuery->sum('target_revenue');

        $achievementPercentage = $totalTargetRevenue > 0
            ? round(($totalRevenue / $totalTargetRevenue) * 100, 2)
            : 0.0;

        $totalVisits = (int) $visitsQuery->count();

        return [
            'period_month' => $month,
            'period_year' => $year,
            'total_revenue' => $totalRevenue,
            'total_orders' => $totalOrders,
            'average_order_value' => $averageOrderValue,
            'total_target_revenue' => $totalTargetRevenue,
            'target_achievement_percentage' => $achievementPercentage,
            'total_visits' => $totalVisits,
        ];
    }

    /**
     * Get revenue breakdown per sales user for admin dashboard without N+1 problem.
     *
     * @return array<int, array{
     *     user_id: int,
     *     name: string,
     *     email: string,
     *     phone: string|null,
     *     actual_revenue: float,
     *     total_orders: int,
     *     target_revenue: float,
     *     revenue_achievement_percentage: float,
     *     actual_visits: int,
     *     target_visits: int,
     *     visit_achievement_percentage: float,
     *     kpi_score: float
     * }>
     */
    public function getSalesRevenueBreakdown(int $month, int $year, ?User $admin = null): array
    {
        // 1. Fetch all sales users belonging to this admin
        $salesQuery = User::where('role', 'sales')
            ->where('is_active', true);

        if ($admin && ! $admin->isSuperAdmin()) {
            $salesQuery->where('admin_id', $admin->id);
        }

        $salesUsers = $salesQuery->get(['id', 'name', 'email', 'phone']);

        if ($salesUsers->isEmpty()) {
            return [];
        }

        $userIds = $salesUsers->pluck('id');

        // 2. Fetch revenue & orders per sales in 1 query
        $salesOrders = Order::whereIn('user_id', $userIds)
            ->where('status', 'completed')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->groupBy('user_id')
            ->selectRaw('user_id, COALESCE(SUM(total_amount), 0) as actual_revenue, COUNT(id) as total_orders')
            ->pluck('actual_revenue', 'user_id');

        $salesOrderCounts = Order::whereIn('user_id', $userIds)
            ->where('status', 'completed')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(id) as count')
            ->pluck('count', 'user_id');

        // 3. Fetch visits per sales in 1 query
        $salesVisits = StoreVisit::whereIn('user_id', $userIds)
            ->whereMonth('visited_at', $month)
            ->whereYear('visited_at', $year)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(id) as visit_count')
            ->pluck('visit_count', 'user_id');

        // 4. Fetch targets for month/year in 1 query
        $targets = SalesTarget::whereIn('user_id', $userIds)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->get()
            ->keyBy('user_id');

        $results = [];

        foreach ($salesUsers as $user) {
            $actualRevenue = (float) ($salesOrders[$user->id] ?? 0.0);
            $totalOrders = (int) ($salesOrderCounts[$user->id] ?? 0);
            $actualVisits = (int) ($salesVisits[$user->id] ?? 0);

            $target = $targets->get($user->id);
            $targetRevenue = (float) ($target?->target_revenue ?? 0.0);
            $targetVisits = (int) ($target?->target_visits ?? 0);

            $revenuePct = $targetRevenue > 0
                ? round(($actualRevenue / $targetRevenue) * 100, 2)
                : ($actualRevenue > 0 ? 100.0 : 0.0);

            $visitPct = $targetVisits > 0
                ? round(($actualVisits / $targetVisits) * 100, 2)
                : ($actualVisits > 0 ? 100.0 : 0.0);

            // KPI score: 70% revenue achievement + 30% visit achievement (capped at 150 for scoring)
            $kpiScore = round((min($revenuePct, 150) * 0.70) + (min($visitPct, 150) * 0.30), 1);

            $results[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'actual_revenue' => $actualRevenue,
                'total_orders' => $totalOrders,
                'target_revenue' => $targetRevenue,
                'revenue_achievement_percentage' => $revenuePct,
                'actual_visits' => $actualVisits,
                'target_visits' => $targetVisits,
                'visit_achievement_percentage' => $visitPct,
                'kpi_score' => $kpiScore,
            ];
        }

        // Sort descending by actual revenue
        usort($results, fn ($a, $b) => $b['actual_revenue'] <=> $a['actual_revenue']);

        return $results;
    }

    /**
     * Get individual sales KPI and target progress for current sales user.
     *
     * @return array{
     *     user_id: int,
     *     sales_name: string,
     *     period_month: int,
     *     period_year: int,
     *     target_revenue: float,
     *     actual_revenue: float,
     *     revenue_remaining: float,
     *     revenue_achievement_percentage: float,
     *     target_visits: int,
     *     actual_visits: int,
     *     visits_remaining: int,
     *     visit_achievement_percentage: float,
     *     total_transactions: int,
     *     days_remaining_in_month: int,
     *     daily_revenue_run_rate_needed: float,
     *     status: string
     * }
     */
    public function getSalesPersonalKpi(User $salesUser, int $month, int $year): array
    {
        $target = SalesTarget::where('user_id', $salesUser->id)
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->first();

        $targetRevenue = (float) ($target?->target_revenue ?? 0.0);
        $targetVisits = (int) ($target?->target_visits ?? 0);

        $orderStats = Order::where('user_id', $salesUser->id)
            ->where('status', 'completed')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue, COUNT(id) as count')
            ->first();

        $actualRevenue = (float) ($orderStats->revenue ?? 0.0);
        $totalOrders = (int) ($orderStats->count ?? 0);

        $actualVisits = (int) StoreVisit::where('user_id', $salesUser->id)
            ->whereMonth('visited_at', $month)
            ->whereYear('visited_at', $year)
            ->count();

        $revenuePct = $targetRevenue > 0
            ? round(($actualRevenue / $targetRevenue) * 100, 2)
            : ($actualRevenue > 0 ? 100.0 : 0.0);

        $visitPct = $targetVisits > 0
            ? round(($actualVisits / $targetVisits) * 100, 2)
            : ($actualVisits > 0 ? 100.0 : 0.0);

        $revenueRemaining = max(0.0, $targetRevenue - $actualRevenue);
        $visitsRemaining = max(0, $targetVisits - $actualVisits);

        // Days remaining calculation
        $now = Carbon::now();
        $isCurrentMonth = ($now->month === $month && $now->year === $year);
        $daysRemaining = $isCurrentMonth ? max(1, $now->endOfMonth()->day - $now->day) : 0;
        $dailyRunRate = ($daysRemaining > 0 && $revenueRemaining > 0)
            ? round($revenueRemaining / $daysRemaining, 2)
            : 0.0;

        $status = 'Sedang Berjalan';
        if ($targetRevenue > 0 && $actualRevenue >= $targetRevenue) {
            $status = 'Target Tercapai! 🎉';
        }

        return [
            'user_id' => $salesUser->id,
            'sales_name' => $salesUser->name,
            'period_month' => $month,
            'period_year' => $year,
            'target_revenue' => $targetRevenue,
            'actual_revenue' => $actualRevenue,
            'revenue_remaining' => $revenueRemaining,
            'revenue_achievement_percentage' => $revenuePct,
            'target_visits' => $targetVisits,
            'actual_visits' => $actualVisits,
            'visits_remaining' => $visitsRemaining,
            'visit_achievement_percentage' => $visitPct,
            'total_transactions' => $totalOrders,
            'days_remaining_in_month' => $daysRemaining,
            'daily_revenue_run_rate_needed' => $dailyRunRate,
            'status' => $status,
        ];
    }

    /**
     * Create or update target for a sales user.
     *
     * @param  array{user_id: int, period_month: int, period_year: int, target_revenue: float, target_visits: int, notes?: string|null}  $data
     */
    public function saveTarget(array $data, ?User $admin = null): SalesTarget
    {
        return SalesTarget::updateOrCreate(
            [
                'user_id' => $data['user_id'],
                'period_month' => $data['period_month'],
                'period_year' => $data['period_year'],
            ],
            [
                'target_revenue' => $data['target_revenue'],
                'target_visits' => $data['target_visits'],
                'admin_id' => $admin?->id,
                'notes' => $data['notes'] ?? null,
            ]
        )->load('user:id,name,email,phone');
    }

    /**
     * List all targets for a given period.
     */
    public function getTargets(int $month, int $year, ?User $admin = null): array
    {
        $query = SalesTarget::with('user:id,name,email,phone')
            ->where('period_month', $month)
            ->where('period_year', $year);

        if ($admin && ! $admin->isSuperAdmin()) {
            $query->where('admin_id', $admin->id);
        }

        return $query->get()->toArray();
    }
}
