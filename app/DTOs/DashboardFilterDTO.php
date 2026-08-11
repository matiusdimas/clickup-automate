<?php

namespace App\DTOs;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardFilterDTO
{
    public function __construct(
        public readonly ?string $module = null,
        public readonly ?string $aplikasi = null,
        public readonly ?string $status = null,
        public readonly ?string $technician = null,
        public readonly string $period = 'current',
        public readonly ?int $year = null,
        public readonly ?int $month = null,
        public readonly ?string $startDate = null,
        public readonly ?string $endDate = null,
    ) {}

    /**
     * Create a DTO instance from the incoming HTTP request.
     */
    public static function fromRequest(Request $request): self
    {
        $module = $request->query('module') ? trim((string) $request->query('module')) : null;
        $aplikasi = $request->query('aplikasi') ? trim((string) $request->query('aplikasi')) : null;
        $status = $request->query('status') ? trim((string) $request->query('status')) : null;
        $technician = $request->query('technician') ? trim((string) $request->query('technician')) : null;

        $startDateRaw = $request->query('start_date') ?? $request->query('start');
        $endDateRaw = $request->query('end_date') ?? $request->query('end');

        $startDate = null;
        $endDate = null;

        if (filled($startDateRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $startDateRaw))) {
            $startDate = trim((string) $startDateRaw);
        }
        if (filled($endDateRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $endDateRaw))) {
            $endDate = trim((string) $endDateRaw);
        }

        // Accept 'period' or 'month' parameter (e.g. '2026-08', 'current', or 'all')
        $rawPeriod = $request->query('period') ?? $request->query('month') ?? ($startDate || $endDate ? 'custom' : 'current');
        $periodStr = strtolower(trim((string) $rawPeriod));

        $year = null;
        $month = null;

        if ($periodStr !== 'all' && $periodStr !== 'custom' && $periodStr !== '') {
            if ($periodStr === 'current') {
                $now = Carbon::now();
                $year = $now->year;
                $month = $now->month;
                $periodStr = $now->format('Y-m');
            } elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $periodStr, $matches)) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
                $periodStr = sprintf('%04d-%02d', $year, $month);
            } else {
                $reqYear = $request->query('year');
                $reqMonth = $request->query('month');
                if (is_numeric($reqYear) && is_numeric($reqMonth)) {
                    $year = (int) $reqYear;
                    $month = (int) $reqMonth;
                    $periodStr = sprintf('%04d-%02d', $year, $month);
                } else {
                    $now = Carbon::now();
                    $year = $now->year;
                    $month = $now->month;
                    $periodStr = $now->format('Y-m');
                }
            }
        } elseif ($startDate || $endDate) {
            $periodStr = 'custom';
        } else {
            $periodStr = 'all';
        }

        return new self(
            module: $module !== '' ? $module : null,
            aplikasi: $aplikasi !== '' ? $aplikasi : null,
            status: $status !== '' ? $status : null,
            technician: $technician !== '' ? $technician : null,
            period: $periodStr,
            year: $year,
            month: $month,
            startDate: $startDate,
            endDate: $endDate,
        );
    }

    public function hasCustomDateRange(): bool
    {
        return filled($this->startDate) || filled($this->endDate);
    }

    public function isAllTime(): bool
    {
        return ! $this->hasCustomDateRange() && ($this->period === 'all' || ($this->year === null && $this->month === null));
    }

    public function toCacheKey(): string
    {
        return 'clickup_dashboard_' . md5(json_encode([
            'module' => $this->module,
            'aplikasi' => $this->aplikasi,
            'status' => $this->status,
            'technician' => $this->technician,
            'period' => $this->period,
            'year' => $this->year,
            'month' => $this->month,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]));
    }

    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'aplikasi' => $this->aplikasi,
            'status' => $this->status,
            'technician' => $this->technician,
            'period' => $this->period,
            'year' => $this->year,
            'month' => $this->month,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }
}
