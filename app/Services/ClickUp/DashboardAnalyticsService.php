<?php

namespace App\Services\ClickUp;

use App\DTOs\DashboardFilterDTO;
use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly DashboardFilterService $filterService
    ) {}

    /**
     * Compute comprehensive dashboard metrics for the given filter parameters.
     * Uses single-query conditional aggregation for high performance.
     */
    public function getAnalytics(DashboardFilterDTO $dto): array
    {
        $cacheKey = $dto->toCacheKey();

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($dto) {
            $baseQuery = ClickUpTaskCache::query();
            $this->filterService->applyFilters($baseQuery, $dto);

            // 1. Overall Summary Metrics (Single aggregate query)
            $summaryAgg = (clone $baseQuery)
                ->selectRaw("
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('open', 'new', 'unassigned') THEN 1 ELSE 0 END) as open_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('in progress', 'in-progress', 'work in progress') THEN 1 ELSE 0 END) as in_progress_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('on hold', 'on-hold', 'pending', 'stopclock') THEN 1 ELSE 0 END) as on_hold_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('closed', 'resolved', 'completed', 'done') THEN 1 ELSE 0 END) as closed_tasks,
                    SUM(CASE WHEN (overdue_status = 'overdue' OR resolved_overdue = 'true' OR response_overdue = 'overdue') THEN 1 ELSE 0 END) as overdue_tasks
                ")
                ->first();

            $totalTasks = (int) ($summaryAgg->total_tasks ?? 0);
            $openTasks = (int) ($summaryAgg->open_tasks ?? 0);
            $inProgressTasks = (int) ($summaryAgg->in_progress_tasks ?? 0);
            $onHoldTasks = (int) ($summaryAgg->on_hold_tasks ?? 0);
            $closedTasks = (int) ($summaryAgg->closed_tasks ?? 0);
            $overdueTasks = (int) ($summaryAgg->overdue_tasks ?? 0);

            $resolutionRate = $totalTasks > 0 ? round(($closedTasks / $totalTasks) * 100, 1) : 0.0;
            $lastSyncedAt = ClickUpModule::query()->whereNotNull('last_synced_at')->max('last_synced_at');

            $summary = [
                'total_tasks' => $totalTasks,
                'open_tasks' => $openTasks,
                'in_progress_tasks' => $inProgressTasks,
                'on_hold_tasks' => $onHoldTasks,
                'closed_tasks' => $closedTasks,
                'resolution_rate_pct' => $resolutionRate,
                'overdue_tasks' => $overdueTasks,
                'within_sla_tasks' => max(0, $totalTasks - $overdueTasks),
                'active_modules_count' => ClickUpModule::query()->where('is_active', true)->count(),
                'last_synced_at' => $lastSyncedAt ? Carbon::parse($lastSyncedAt)->toIso8601String() : null,
                'period' => $dto->period,
                'year' => $dto->year,
                'month' => $dto->month,
            ];

            // 2. Breakdown by Tipe Aplikasi / Main Modules (Single aggregate query with GROUP BY)
            $byModule = (clone $baseQuery)
                ->select('tipe_aplikasi')
                ->selectRaw("
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('open', 'new', 'unassigned') THEN 1 ELSE 0 END) as open_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('in progress', 'in-progress', 'work in progress') THEN 1 ELSE 0 END) as in_progress_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('on hold', 'on-hold', 'pending', 'stopclock') THEN 1 ELSE 0 END) as on_hold_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('closed', 'resolved', 'completed', 'done') THEN 1 ELSE 0 END) as closed_tasks
                ")
                ->whereNotNull('tipe_aplikasi')
                ->where('tipe_aplikasi', '!=', '')
                ->groupBy('tipe_aplikasi')
                ->orderByDesc('total_tasks')
                ->get()
                ->map(function ($item) use ($totalTasks) {
                    $modTotal = (int) $item->total_tasks;
                    $modClosed = (int) $item->closed_tasks;
                    $modOpen = (int) $item->open_tasks;
                    $modInProgress = (int) $item->in_progress_tasks;
                    $modOnHold = (int) $item->on_hold_tasks;

                    return [
                        'tipe_aplikasi' => $item->tipe_aplikasi,
                        'total_tasks' => $modTotal,
                        'open_tasks' => $modOpen,
                        'in_progress_tasks' => $modInProgress,
                        'on_hold_tasks' => $modOnHold,
                        'closed_tasks' => $modClosed,
                        'resolution_rate_pct' => $modTotal > 0 ? round(($modClosed / $modTotal) * 100, 1) : 0.0,
                        'share_pct' => $totalTasks > 0 ? round(($modTotal / $totalTasks) * 100, 1) : 0.0,
                    ];
                });

            // 3. Breakdown by Detail Aplikasi / Sub-Apps (Single aggregate query with GROUP BY)
            $byAplikasi = (clone $baseQuery)
                ->select('aplikasi', 'tipe_aplikasi')
                ->selectRaw("
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('closed', 'resolved', 'completed', 'done') THEN 1 ELSE 0 END) as closed_tasks
                ")
                ->whereNotNull('aplikasi')
                ->where('aplikasi', '!=', '')
                ->groupBy('aplikasi', 'tipe_aplikasi')
                ->orderByDesc('total_tasks')
                ->limit(15)
                ->get()
                ->map(function ($item) {
                    $appTotal = (int) $item->total_tasks;
                    $appClosed = (int) $item->closed_tasks;

                    return [
                        'aplikasi' => $item->aplikasi,
                        'tipe_aplikasi' => $item->tipe_aplikasi,
                        'total_tasks' => $appTotal,
                        'closed_tasks' => $appClosed,
                        'open_tasks' => max(0, $appTotal - $appClosed),
                        'resolution_rate_pct' => $appTotal > 0 ? round(($appClosed / $appTotal) * 100, 1) : 0.0,
                    ];
                });

            // 4. Breakdown by Status
            $statusColors = [
                'open' => '#3B82F6',
                'in progress' => '#F59E0B',
                'on hold' => '#8B5CF6',
                'closed' => '#10B981',
            ];

            $byStatus = (clone $baseQuery)
                ->select('status')
                ->selectRaw('COUNT(*) as total_tasks')
                ->whereNotNull('status')
                ->where('status', '!=', '')
                ->groupBy('status')
                ->get()
                ->map(function ($item) use ($totalTasks, $statusColors) {
                    $st = strtolower(trim((string) $item->status));
                    $count = (int) $item->total_tasks;
                    return [
                        'status' => $item->status,
                        'total_tasks' => $count,
                        'percentage' => $totalTasks > 0 ? round(($count / $totalTasks) * 100, 1) : 0.0,
                        'color' => $statusColors[$st] ?? '#64748B',
                    ];
                });

            // 5. Breakdown by Priority
            $priorityColors = [
                'urgent' => '#EF4444',
                'high' => '#F97316',
                'normal' => '#3B82F6',
                'low' => '#64748B',
            ];

            $byPriority = (clone $baseQuery)
                ->select('priority')
                ->selectRaw('COUNT(*) as total_tasks')
                ->groupBy('priority')
                ->orderByDesc('total_tasks')
                ->get()
                ->map(function ($item) use ($totalTasks, $priorityColors) {
                    $prio = strtolower(trim((string) $item->priority)) ?: 'unassigned';
                    $count = (int) $item->total_tasks;
                    return [
                        'priority' => filled($item->priority) ? ucfirst($item->priority) : 'Unassigned',
                        'total_tasks' => $count,
                        'percentage' => $totalTasks > 0 ? round(($count / $totalTasks) * 100, 1) : 0.0,
                        'color' => $priorityColors[$prio] ?? '#94A3B8',
                    ];
                });

            // 6. Breakdown by Technician (Top 10 - Single aggregate query with GROUP BY)
            $byTechnician = (clone $baseQuery)
                ->select('technician')
                ->selectRaw("
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN LOWER(status) IN ('closed', 'resolved', 'completed', 'done') THEN 1 ELSE 0 END) as closed_tasks
                ")
                ->whereNotNull('technician')
                ->where('technician', '!=', '')
                ->groupBy('technician')
                ->orderByDesc('total_tasks')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    $techTotal = (int) $item->total_tasks;
                    $techClosed = (int) $item->closed_tasks;

                    return [
                        'technician' => $item->technician,
                        'total_tasks' => $techTotal,
                        'closed_tasks' => $techClosed,
                        'open_tasks' => max(0, $techTotal - $techClosed),
                        'resolution_rate_pct' => $techTotal > 0 ? round(($techClosed / $techTotal) * 100, 1) : 0.0,
                    ];
                });

            // 7. Recent Tasks Feed
            $recentTasks = (clone $baseQuery)
                ->orderByDesc('updated_at')
                ->limit(15)
                ->get()
                ->map(fn (ClickUpTaskCache $task) => [
                    'id' => $task->id,
                    'clickup_task_id' => $task->clickup_task_id,
                    'custom_id' => $task->custom_id,
                    'tiket_id' => $task->tiket_id,
                    'name' => $task->name,
                    'tipe_aplikasi' => $task->tipe_aplikasi,
                    'aplikasi' => $task->aplikasi,
                    'status' => $task->status,
                    'description' => $task->description,
                    'resolution' => $task->resolution,
                    'technician' => $task->technician,
                    'requestor_name' => $task->requestor_name,
                    'created_time' => $task->created_time,
                    'resolved_time' => $task->resolved_time,
                    'updated_at' => $task->updated_at?->toIso8601String(),
                ]);

            // 8. Dynamic Distinct Options for Dropdown Filters
            $availableFilters = $this->filterService->getAvailableFilters();

            return [
                'success' => true,
                'timestamp' => now()->toIso8601String(),
                'filters' => $dto->toArray(),
                'available_filters' => $availableFilters,
                'data' => [
                    'summary' => $summary,
                    'by_module' => $byModule->values()->toArray(),
                    'by_application' => $byAplikasi->values()->toArray(),
                    'by_status' => $byStatus->values()->toArray(),
                    'by_priority' => $byPriority->values()->toArray(),
                    'by_technician' => $byTechnician->values()->toArray(),
                    'recent_tasks' => $recentTasks->values()->toArray(),
                ],
            ];
        });
    }
}
