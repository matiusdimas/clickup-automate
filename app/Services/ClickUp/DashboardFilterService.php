<?php

namespace App\Services\ClickUp;

use App\DTOs\DashboardFilterDTO;
use App\Models\ClickUpTaskCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardFilterService
{
    private const AVAILABLE_FILTERS_CACHE_KEY = 'clickup_available_filters_v2';
    private const AVAILABLE_FILTERS_TTL = 300; // 5 minutes

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

        // 5. Date Filter (Dynamic Custom Range vs Month Presets - NO 90 DAY LIMIT)
        if ($dto->hasCustomDateRange()) {
            $startDate = $dto->startDate ? Carbon::parse($dto->startDate)->startOfDay() : null;
            $endDate = $dto->endDate ? Carbon::parse($dto->endDate)->endOfDay() : null;

            if ($startDate || $endDate) {
                $query->where(function ($dateQ) use ($startDate, $endDate) {
                    $dateQ->whereNotNull('created_time')->where('created_time', '!=', '');

                    $patterns = [];
                    if ($startDate && $endDate) {
                        $patterns = $this->buildDateRangePatterns($startDate, $endDate);
                    } elseif ($startDate) {
                        // Start date only
                        $patterns[] = $startDate->format('M d, Y');
                        $patterns[] = $startDate->format('Y-m');
                    } elseif ($endDate) {
                        // End date only
                        $patterns[] = $endDate->format('M d, Y');
                        $patterns[] = $endDate->format('Y-m');
                    }

                    $dateQ->where(function ($sub) use ($startDate, $endDate, $patterns) {
                        if (! empty($patterns)) {
                            $sub->where(function ($pSub) use ($patterns) {
                                foreach ($patterns as $pat) {
                                    if (str_contains($pat, '%')) {
                                        $pSub->orWhere('created_time', 'like', $pat);
                                    } else {
                                        $pSub->orWhere('created_time', 'like', "{$pat}%");
                                    }
                                }
                            });
                        }

                        // Direct ISO / timestamp column range matching
                        $sub->orWhere(function ($dtSub) use ($startDate, $endDate) {
                            if ($startDate) {
                                $dtSub->where('created_time', '>=', $startDate->toDateTimeString());
                            }
                            if ($endDate) {
                                $dtSub->where('created_time', '<=', $endDate->toDateTimeString());
                            }
                        });
                    });
                });
            }
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
                // Direct timestamp / datetime column matching
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
     * Dynamically build date search patterns for any date range without limit.
     */
    private function buildDateRangePatterns(Carbon $startDate, Carbon $endDate): array
    {
        $patterns = [];
        $diffDays = $startDate->diffInDays($endDate);

        // For short ranges (<= 31 days) or same month, generate day-by-day exact patterns
        if ($diffDays <= 31 || $startDate->format('Y-m') === $endDate->format('Y-m')) {
            $curr = $startDate->copy();
            while ($curr->lte($endDate)) {
                $patterns[] = $curr->format('M d, Y');
                $patterns[] = $curr->format('M j, Y');
                $patterns[] = $curr->format('Y-m-d');
                $patterns[] = $curr->format('m/d/Y');
                $patterns[] = $curr->format('d/m/Y');
                $curr->addDay();
            }

            return array_values(array_unique($patterns));
        }

        // For longer date ranges (> 31 days):
        // 1. Partial days in start month
        $startMonthEnd = $startDate->copy()->endOfMonth();
        $curr = $startDate->copy();
        while ($curr->lte($startMonthEnd) && $curr->lte($endDate)) {
            $patterns[] = $curr->format('M d, Y');
            $patterns[] = $curr->format('M j, Y');
            $patterns[] = $curr->format('Y-m-d');
            $patterns[] = $curr->format('m/d/Y');
            $patterns[] = $curr->format('d/m/Y');
            $curr->addDay();
        }

        // 2. Interior full months
        $nextMonth = $startDate->copy()->addMonth()->startOfMonth();
        $endMonthStart = $endDate->copy()->startOfMonth();

        while ($nextMonth->lt($endMonthStart)) {
            $m3Letter = $nextMonth->format('M');
            $year = $nextMonth->format('Y');
            $ymIso = $nextMonth->format('Y-m');
            $m2Digit = $nextMonth->format('m');

            $patterns[] = "%{$m3Letter}%{$year}%";
            $patterns[] = "{$ymIso}%";
            $patterns[] = "{$m2Digit}/%/{$year}%";
            $patterns[] = "%/{$m2Digit}/{$year}%";

            $nextMonth->addMonth();
        }

        // 3. Partial days in end month
        $curr = $endDate->copy()->startOfMonth();
        while ($curr->lte($endDate)) {
            $patterns[] = $curr->format('M d, Y');
            $patterns[] = $curr->format('M j, Y');
            $patterns[] = $curr->format('Y-m-d');
            $patterns[] = $curr->format('m/d/Y');
            $patterns[] = $curr->format('d/m/Y');
            $curr->addDay();
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Fetch distinct filter options from the database for dynamic UI rendering.
     */
    public function getAvailableFilters(): array
    {
        return Cache::remember(self::AVAILABLE_FILTERS_CACHE_KEY, self::AVAILABLE_FILTERS_TTL, function () {
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

            // Extract distinct ticket creation months dynamically using fast regex matching
            $rawCreatedTimes = ClickUpTaskCache::query()
                ->select('created_time')
                ->whereNotNull('created_time')
                ->where('created_time', '!=', '')
                ->distinct()
                ->pluck('created_time');

            $monthMap = [
                'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
                'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
                'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
            ];
            $detectedMonths = [];

            foreach ($rawCreatedTimes as $rawDate) {
                if (blank($rawDate)) continue;
                $trimmed = trim((string) $rawDate);

                if (preg_match('/^([A-Za-z]{3})\s+\d+,\s+(\d{4})/', $trimmed, $m)) {
                    $mName = ucfirst(strtolower($m[1]));
                    if (isset($monthMap[$mName])) {
                        $year = (int) $m[2];
                        $month = $monthMap[$mName];
                        $ym = sprintf('%04d-%02d', $year, $month);
                        $detectedMonths[$ym] = ['year' => $year, 'month' => $month, 'ym' => $ym];
                        continue;
                    }
                }

                if (preg_match('/^(\d{4})-(\d{2})/', $trimmed, $m)) {
                    $year = (int) $m[1];
                    $month = (int) $m[2];
                    if ($month >= 1 && $month <= 12) {
                        $ym = sprintf('%04d-%02d', $year, $month);
                        $detectedMonths[$ym] = ['year' => $year, 'month' => $month, 'ym' => $ym];
                        continue;
                    }
                }

                $parsed = DateFormattingService::parseToCarbon($trimmed);
                if ($parsed) {
                    $ym = $parsed->format('Y-m');
                    $detectedMonths[$ym] = ['year' => $parsed->year, 'month' => $parsed->month, 'ym' => $ym];
                }
            }

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
        });
    }

    /**
     * Clear available filters cache when tasks data is updated.
     */
    public function clearAvailableFiltersCache(): void
    {
        Cache::forget(self::AVAILABLE_FILTERS_CACHE_KEY);
    }
}
