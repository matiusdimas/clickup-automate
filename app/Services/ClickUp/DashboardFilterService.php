<?php

namespace App\Services\ClickUp;

use App\DTOs\DashboardFilterDTO;
use App\Models\ClickUpTaskCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardFilterService
{
    /**
     * Apply filter criteria from DTO to the task cache query.
     */
    public function applyFilters(Builder $query, DashboardFilterDTO $dto): Builder
    {
        // 1. Module / Tipe Aplikasi Filter
        if (filled($dto->module)) {
            $mod = trim($dto->module);
            $query->where(function ($q) use ($mod) {
                $q->where('tipe_aplikasi', strtoupper($mod))
                  ->orWhere('tipe_aplikasi', $mod)
                  ->orWhere('aplikasi', $mod);
            });
        }

        // 2. Sub-Application / Aplikasi Filter
        if (filled($dto->aplikasi)) {
            $app = trim($dto->aplikasi);
            $query->where(function ($q) use ($app) {
                $q->where('aplikasi', $app)
                  ->orWhere('tipe_aplikasi', $app);
            });
        }

        // 3. Status Filter
        if (filled($dto->status)) {
            $st = strtolower(trim($dto->status));
            $query->where(function ($q) use ($st) {
                $q->where('status', $st)
                  ->orWhere(DB::raw('LOWER(status)'), $st);
            });
        }

        // 4. Technician Filter
        if (filled($dto->technician)) {
            $tech = trim($dto->technician);
            $query->where('technician', $tech);
        }

        // 5. Date / Month Filter (default to current month unless 'all' is requested)
        if (! $dto->isAllTime() && $dto->year !== null && $dto->month !== null) {
            $year = $dto->year;
            $month = $dto->month;
            $monthPad = sprintf('%02d', $month);
            $monthName3Letter = Carbon::createFromDate($year, $month, 1)->format('M');
            $yearMonthIso = "{$year}-{$monthPad}";

            $query->where(function ($dateQ) use ($year, $month, $monthName3Letter, $yearMonthIso) {
                $dateQ->where(function ($sub) use ($year, $month) {
                    $sub->whereYear('created_at', $year)
                        ->whereMonth('created_at', $month);
                })
                ->orWhere(function ($sub) use ($year, $monthName3Letter, $yearMonthIso) {
                    $sub->whereNotNull('created_time')
                        ->where('created_time', '!=', '')
                        ->where(function ($strQ) use ($year, $monthName3Letter, $yearMonthIso) {
                            $strQ->where('created_time', 'like', "%{$monthName3Letter}%{$year}%")
                                 ->orWhere('created_time', 'like', "{$yearMonthIso}%");
                        });
                });
            });
        }

        return $query;
    }

    /**
     * Fetch distinct filter options from the database for dynamic UI rendering.
     */
    public function getAvailableFilters(): array
    {
        // Distinct Modules (tipe_aplikasi)
        $modules = ClickUpTaskCache::query()
            ->select('tipe_aplikasi')
            ->whereNotNull('tipe_aplikasi')
            ->where('tipe_aplikasi', '!=', '')
            ->distinct()
            ->orderBy('tipe_aplikasi')
            ->pluck('tipe_aplikasi')
            ->values()
            ->toArray();

        // Distinct Sub-Applications (aplikasi)
        $applications = ClickUpTaskCache::query()
            ->select('aplikasi')
            ->whereNotNull('aplikasi')
            ->where('aplikasi', '!=', '')
            ->distinct()
            ->orderBy('aplikasi')
            ->pluck('aplikasi')
            ->values()
            ->toArray();

        // Distinct Technicians
        $technicians = ClickUpTaskCache::query()
            ->select('technician')
            ->whereNotNull('technician')
            ->where('technician', '!=', '')
            ->distinct()
            ->orderBy('technician')
            ->pluck('technician')
            ->values()
            ->toArray();

        // Distinct Statuses
        $rawStatuses = ClickUpTaskCache::query()
            ->select('status')
            ->whereNotNull('status')
            ->where('status', '!=', '')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->values();

        $statuses = $rawStatuses->map(function ($st) {
            $lower = strtolower(trim((string) $st));
            if ($lower === 'open') return 'Open';
            if ($lower === 'in progress' || $lower === 'in-progress') return 'In Progress';
            if ($lower === 'on hold' || $lower === 'on-hold') return 'On Hold';
            if ($lower === 'closed' || $lower === 'resolved') return 'Closed';
            return ucwords($st);
        })->unique()->values()->toArray();

        // Generate Available Month Options (Current month + past 12 months)
        $periods = [
            ['value' => 'current', 'label' => 'Bulan Ini (' . Carbon::now()->isoFormat('MMMM YYYY') . ')'],
            ['value' => 'all', 'label' => 'Semua Waktu (All Time)'],
        ];

        $indonesianMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        for ($i = 0; $i < 12; $i++) {
            $dt = Carbon::now()->subMonths($i);
            $val = $dt->format('Y-m');
            $label = ($indonesianMonths[$dt->month] ?? $dt->format('F')) . ' ' . $dt->year;

            // Avoid duplicating 'current'
            if ($i > 0) {
                $periods[] = ['value' => $val, 'label' => $label];
            }
        }

        return [
            'modules' => $modules,
            'applications' => $applications,
            'technicians' => $technicians,
            'statuses' => $statuses,
            'periods' => $periods,
        ];
    }
}
