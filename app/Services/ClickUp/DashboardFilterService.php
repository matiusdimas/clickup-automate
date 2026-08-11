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

        // 5. Date Filter (Custom Range vs Month Presets)
        if ($dto->hasCustomDateRange()) {
            $startDate = $dto->startDate ? Carbon::parse($dto->startDate)->startOfDay() : null;
            $endDate = $dto->endDate ? Carbon::parse($dto->endDate)->endOfDay() : null;

            $query->where(function ($dateQ) use ($startDate, $endDate) {
                $dateQ->where(function ($sub) use ($startDate, $endDate) {
                    $sub->whereNotNull('created_time')
                        ->where('created_time', '!=', '');
                    if ($startDate) {
                        $sub->where('created_time', '>=', $startDate->toDateTimeString());
                    }
                    if ($endDate) {
                        $sub->where('created_time', '<=', $endDate->toDateTimeString());
                    }
                });

                if ($startDate || $endDate) {
                    $dateQ->orWhere(function ($sub) use ($startDate, $endDate) {
                        $sub->whereNotNull('created_time')
                            ->where('created_time', '!=', '');
                        if ($startDate) {
                            $sub->where('created_time', '>=', $startDate->format('Y-m-d'));
                        }
                        if ($endDate) {
                            $sub->where('created_time', '<=', $endDate->format('Y-m-d') . ' 23:59:59');
                        }
                    });
                }
            });
        } elseif (! $dto->isAllTime() && $dto->year !== null && $dto->month !== null) {
            $year = $dto->year;
            $month = $dto->month;
            $monthPad = sprintf('%02d', $month);
            $monthName3Letter = Carbon::createFromDate($year, $month, 1)->format('M');
            $yearMonthIso = "{$year}-{$monthPad}";
            $slashYearMonth = "{$monthPad}/%/{$year}";
            $slashMonthYear = "%/{$monthPad}/{$year}";

            $query->where(function ($dateQ) use ($year, $month, $monthName3Letter, $yearMonthIso, $slashYearMonth, $slashMonthYear) {
                // SDP / ClickUp formatted string: "Jul 29, 2026 10:24 AM"
                $dateQ->where(function ($sub) use ($year, $monthName3Letter) {
                    $sub->whereNotNull('created_time')
                        ->where('created_time', '!=', '')
                        ->where('created_time', 'like', "%{$monthName3Letter}%{$year}%");
                })
                // ISO format: "2026-07-29..."
                ->orWhere(function ($sub) use ($yearMonthIso) {
                    $sub->whereNotNull('created_time')
                        ->where('created_time', '!=', '')
                        ->where('created_time', 'like', "{$yearMonthIso}%");
                })
                // Slash format: "07/29/2026..." or "29/07/2026..."
                ->orWhere(function ($sub) use ($slashYearMonth, $slashMonthYear) {
                    $sub->whereNotNull('created_time')
                        ->where('created_time', '!=', '')
                        ->where(function ($sQ) use ($slashYearMonth, $slashMonthYear) {
                            $sQ->where('created_time', 'like', "{$slashYearMonth}%")
                               ->orWhere('created_time', 'like', "{$slashMonthYear}%");
                        });
                })
                // Direct timestamp / datetime column matching if converted
                ->orWhere(function ($sub) use ($year, $month) {
                    $sub->whereNotNull('created_time')
                        ->where('created_time', '!=', '')
                        ->whereYear('created_time', $year)
                        ->whereMonth('created_time', $month);
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

        // Extract distinct ticket creation months dynamically from created_time
        $rawCreatedTimes = ClickUpTaskCache::query()
            ->select('created_time')
            ->whereNotNull('created_time')
            ->where('created_time', '!=', '')
            ->distinct()
            ->pluck('created_time');

        $detectedMonths = [];
        foreach ($rawCreatedTimes as $rawDate) {
            $parsed = DateFormattingService::parseToCarbon($rawDate);
            if ($parsed) {
                $ym = $parsed->format('Y-m');
                $detectedMonths[$ym] = [
                    'year' => $parsed->year,
                    'month' => $parsed->month,
                    'ym' => $ym,
                ];
            }
        }

        // Sort descending (newest month first)
        krsort($detectedMonths);

        $indonesianMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $periods = [
            ['value' => 'current', 'label' => 'Bulan Ini (' . Carbon::now()->isoFormat('MMMM YYYY') . ')'],
            ['value' => 'all', 'label' => 'Semua Waktu (All Time)'],
        ];

        foreach ($detectedMonths as $ym => $info) {
            $label = ($indonesianMonths[$info['month']] ?? Carbon::createFromDate($info['year'], $info['month'], 1)->format('F')) . ' ' . $info['year'];
            $periods[] = ['value' => $ym, 'label' => $label];
        }

        // Fallback: If no distinct created_time detected, provide current year months
        if (count($detectedMonths) === 0) {
            for ($i = 1; $i <= 12; $i++) {
                $dt = Carbon::now()->subMonths($i - 1);
                $val = $dt->format('Y-m');
                $label = ($indonesianMonths[$dt->month] ?? $dt->format('F')) . ' ' . $dt->year;
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
