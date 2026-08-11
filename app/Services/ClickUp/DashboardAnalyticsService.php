<?php

namespace App\Services\ClickUp;

use App\DTOs\DashboardFilterDTO;
use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DashboardAnalyticsService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly DashboardFilterService $filterService
    ) {}

    /**
     * Compute comprehensive dashboard metrics for the given filter parameters.
     */
    public function getAnalytics(DashboardFilterDTO $dto): array
    {
        $cacheKey = $dto->toCacheKey();

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($dto) {
            $baseQuery = ClickUpTaskCache::query();
            $this->filterService->applyFilters($baseQuery, $dto);

            // 1. Overall Summary Metrics
            $totalTasks = (clone $baseQuery)->count();
            $openTasks = (clone $baseQuery)->whereIn(DB::raw('LOWER(status)'), ['open', 'new', 'unassigned'])->count();
            $inProgressTasks = (clone $baseQuery)->whereIn(DB::raw('LOWER(status)'), ['in progress', 'in-progress', 'work in progress'])->count();
            $onHoldTasks = (clone $baseQuery)->whereIn(DB::raw('LOWER(status)'), ['on hold', 'on-hold', 'pending', 'stopclock'])->count();
            $closedTasks = (clone $baseQuery)->whereIn(DB::raw('LOWER(status)'), ['closed', 'resolved', 'completed', 'done'])->count();

            $resolutionRate = $totalTasks > 0 ? round(($closedTasks / $totalTasks) * 100, 1) : 0.0;

            $overdueTasks = (clone $baseQuery)
                ->where(function ($q) {
                    $q->where('overdue_status', 'overdue')
                      ->orWhere('resolved_overdue', 'true')
                      ->orWhere('response_overdue', 'overdue');
                })->count();

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

            // 2. Breakdown by Tipe Aplikasi (Main Modules)
            $byModule = (clone $baseQuery)
                ->select('tipe_aplikasi', DB::raw('count(*) as total'))
                ->whereNotNull('tipe_aplikasi')
                ->where('tipe_aplikasi', '!=', '')
                ->groupBy('tipe_aplikasi')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) use ($baseQuery, $totalTasks) {
                    $modName = $item->tipe_aplikasi;
                    $modTotal = (int) $item->total;
                    $modClosed = (clone $baseQuery)->where('tipe_aplikasi', $modName)->whereIn(DB::raw('LOWER(status)'), ['closed', 'resolved', 'completed', 'done'])->count();
                    $modOpen = (clone $baseQuery)->where('tipe_aplikasi', $modName)->whereIn(DB::raw('LOWER(status)'), ['open', 'new', 'unassigned'])->count();
                    $modInProgress = (clone $baseQuery)->where('tipe_aplikasi', $modName)->whereIn(DB::raw('LOWER(status)'), ['in progress', 'in-progress'])->count();
                    $modOnHold = (clone $baseQuery)->where('tipe_aplikasi', $modName)->whereIn(DB::raw('LOWER(status)'), ['on hold', 'on-hold', 'stopclock'])->count();

                    return [
                        'tipe_aplikasi' => $modName,
                        'total_tasks' => $modTotal,
                        'open_tasks' => $modOpen,
                        'in_progress_tasks' => $modInProgress,
                        'on_hold_tasks' => $modOnHold,
                        'closed_tasks' => $modClosed,
                        'resolution_rate_pct' => $modTotal > 0 ? round(($modClosed / $modTotal) * 100, 1) : 0.0,
                        'share_pct' => $totalTasks > 0 ? round(($modTotal / $totalTasks) * 100, 1) : 0.0,
                    ];
                });

            // 3. Breakdown by Detail Aplikasi (Sub-Apps)
            $byAplikasi = (clone $baseQuery)
                ->select('aplikasi', 'tipe_aplikasi', DB::raw('count(*) as total'))
                ->whereNotNull('aplikasi')
                ->where('aplikasi', '!=', '')
                ->groupBy('aplikasi', 'tipe_aplikasi')
                ->orderByDesc('total')
                ->limit(15)
                ->get()
                ->map(function ($item) use ($baseQuery) {
                    $appTotal = (int) $item->total;
                    $appClosed = (clone $baseQuery)->where('aplikasi', $item->aplikasi)->whereIn(DB::raw('LOWER(status)'), ['closed', 'resolved', 'completed', 'done'])->count();

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
                ->select('status', DB::raw('count(*) as total'))
                ->whereNotNull('status')
                ->where('status', '!=', '')
                ->groupBy('status')
                ->get()
                ->map(function ($item) use ($totalTasks, $statusColors) {
                    $st = strtolower(trim((string) $item->status));
                    return [
                        'status' => $item->status,
                        'total_tasks' => (int) $item->total,
                        'percentage' => $totalTasks > 0 ? round(((int) $item->total / $totalTasks) * 100, 1) : 0.0,
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
                ->select('priority', DB::raw('count(*) as total'))
                ->groupBy('priority')
                ->orderByDesc('total')
                ->get()
                ->map(function ($item) use ($totalTasks, $priorityColors) {
                    $prio = strtolower(trim((string) $item->priority)) ?: 'unassigned';
                    return [
                        'priority' => filled($item->priority) ? ucfirst($item->priority) : 'Unassigned',
                        'total_tasks' => (int) $item->total,
                        'percentage' => $totalTasks > 0 ? round(((int) $item->total / $totalTasks) * 100, 1) : 0.0,
                        'color' => $priorityColors[$prio] ?? '#94A3B8',
                    ];
                });

            // 6. Breakdown by Technician (Top 10)
            $byTechnician = (clone $baseQuery)
                ->select('technician', DB::raw('count(*) as total'))
                ->whereNotNull('technician')
                ->where('technician', '!=', '')
                ->groupBy('technician')
                ->orderByDesc('total')
                ->limit(10)
                ->get()
                ->map(function ($item) use ($baseQuery) {
                    $techName = $item->technician;
                    $techTotal = (int) $item->total;
                    $techClosed = (clone $baseQuery)->where('technician', $techName)->whereIn(DB::raw('LOWER(status)'), ['closed', 'resolved', 'completed', 'done'])->count();

                    return [
                        'technician' => $techName,
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
                    'by_module' => $byModule,
                    'by_application' => $byAplikasi,
                    'by_status' => $byStatus,
                    'by_priority' => $byPriority,
                    'by_technician' => $byTechnician,
                    'recent_tasks' => $recentTasks,
                ],
            ];
        });
    }
}
