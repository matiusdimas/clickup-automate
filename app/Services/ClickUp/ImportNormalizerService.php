<?php

namespace App\Services\ClickUp;

use Illuminate\Support\Str;

class ImportNormalizerService
{
    public function normalizeImportRow(array $row, $rules = [], $techMappings = [], string $sourceFormat = 'ebesha'): array
    {
        $normalized = collect($row)
            ->mapWithKeys(function ($value, $key) {
                return [Str::of((string) $key)->lower()->replace(['-', '_'], ' ')->squish()->toString() => is_string($value) ? trim($value) : $value];
            })
            ->all();

        $nomorTiket = collect([
            data_get($normalized, 'nomor tiket'),
            data_get($normalized, 'ticket number'),
            data_get($normalized, 'ticket'),
            data_get($normalized, 'no tiket'),
            data_get($normalized, 'request id'),
            data_get($normalized, 'tiket id'),
        ])->first(fn ($value) => filled($value), '');

        $subject = collect([
            data_get($normalized, 'subject'),
            data_get($normalized, 'judul'),
            data_get($normalized, 'title'),
        ])->first(fn ($value) => filled($value), '');

        $statusVal = collect([
            data_get($normalized, 'request status'),
            data_get($normalized, 'status'),
            'open',
        ])->first(fn ($value) => filled($value), 'open');

        $statusRaw = trim((string) $statusVal);
        $statusLower = strtolower($statusRaw);

        if ($statusLower === 'resolved') {
            $statusMapped = 'closed';
        } elseif ($statusLower === 'stopclock' || $statusLower === 'stop clock' || $statusLower === 'on-hold' || $statusLower === 'on_hold' || $statusLower === 'on hold') {
            $statusMapped = 'on hold';
        } elseif ($statusLower === 'in-progress' || $statusLower === 'in_progress' || $statusLower === 'in progress') {
            $statusMapped = 'in progress';
        } else {
            $statusMapped = $statusRaw ?: 'open';
        }

        $aplikasi = collect([
            data_get($normalized, 'aplikasi'),
            data_get($normalized, 'module'),
            data_get($normalized, 'tipe aplikasi'),
            data_get($normalized, 'subcategory'),
            data_get($normalized, 'category'),
        ])->first(fn ($value) => filled($value), '');

        if (filled($rules)) {
            $evaluator = new Routing\RuleEvaluatorService();
            foreach ($rules as $rule) {
                if ($evaluator->matchesRule($rule, $normalized)) {
                    $aplikasi = is_array($rule) ? ($rule['target_module'] ?? $aplikasi) : ($rule->target_module ?? $aplikasi);
                    // Do not break here, allow newer rules to overwrite older ones for exception cases
                }
            }
        }

        $description = collect([
            data_get($normalized, 'description'),
            data_get($normalized, 'deskripsi'),
        ])->first(fn ($value) => filled($value), '');

        $requestorName = collect([
            data_get($normalized, 'requestor name'),
            data_get($normalized, 'requestor'),
            data_get($normalized, 'requester name'),
            data_get($normalized, 'requester'),
            data_get($normalized, 'nama requestor'),
            data_get($normalized, 'contact'),
        ])->first(fn ($value) => filled($value), '');

        $resolution = collect([
            data_get($normalized, 'resolution'),
            data_get($normalized, 'resolusi'),
            data_get($normalized, 'solution'),
        ])->first(fn ($value) => filled($value), '');

        $createdTimeRaw = collect([
            data_get($normalized, 'created date'),
            data_get($normalized, 'created time'),
            data_get($normalized, 'created at'),
            data_get($normalized, 'waktu dibuat'),
            data_get($normalized, 'tanggal dibuat'),
        ])->first(fn ($value) => filled($value), '');

        $resolvedTimeRaw = collect([
            data_get($normalized, 'resolved date'),
            data_get($normalized, 'resolved time'),
            data_get($normalized, 'solved date'),
            data_get($normalized, 'solved time'),
            data_get($normalized, 'waktu selesai'),
            data_get($normalized, 'tanggal selesai'),
            data_get($normalized, 'completed date'),
            data_get($normalized, 'completed time'),
        ])->first(fn ($value) => filled($value), '');

        $createdTime = DateFormattingService::format($createdTimeRaw);
        $resolvedTime = DateFormattingService::format($resolvedTimeRaw);

        $emailAddress = collect([
            data_get($normalized, 'email address'),
            data_get($normalized, 'email'),
            data_get($normalized, 'alamat email'),
        ])->first(fn ($value) => filled($value), '');

        $isSdp = strtolower(trim((string) $sourceFormat)) === 'sdp';

        if ($isSdp) {
            $technician = collect([
                data_get($normalized, 'inisial teknisi'),
                data_get($normalized, 'technician initial'),
                data_get($normalized, 'inisial'),
                data_get($normalized, 'initial'),
                data_get($normalized, 'initials'),
                data_get($normalized, 'teknisi inisial'),
                data_get($normalized, 'tech initial'),
                data_get($normalized, 'inisial handler'),
                // Fallbacks if initial is empty
                data_get($normalized, 'technician'),
                data_get($normalized, 'teknisi'),
                data_get($normalized, 'nama teknisi'),
                data_get($normalized, 'created by'),
                data_get($normalized, 'createdby'),
                data_get($normalized, 'creator'),
                data_get($normalized, 'pembuat'),
                data_get($normalized, 'assigned to'),
                data_get($normalized, 'assignedto'),
                data_get($normalized, 'modified by'),
                data_get($normalized, 'owner'),
                data_get($normalized, 'pic'),
                data_get($normalized, 'handler'),
            ])->first(fn ($value) => filled($value), '');
        } else {
            $technician = collect([
                data_get($normalized, 'technician'),
                data_get($normalized, 'teknisi'),
                data_get($normalized, 'nama teknisi'),
                data_get($normalized, 'inisial teknisi'),
                data_get($normalized, 'technician initial'),
                data_get($normalized, 'inisial'),
                data_get($normalized, 'initial'),
                data_get($normalized, 'initials'),
                data_get($normalized, 'created by'),
                data_get($normalized, 'createdby'),
                data_get($normalized, 'creator'),
                data_get($normalized, 'pembuat'),
                data_get($normalized, 'assigned to'),
                data_get($normalized, 'assignedto'),
                data_get($normalized, 'modified by'),
                data_get($normalized, 'owner'),
                data_get($normalized, 'pic'),
                data_get($normalized, 'handler'),
            ])->first(fn ($value) => filled($value), '');
        }

        if (filled($techMappings) && filled($technician)) {
            $rawTech = strtolower(trim((string) $technician));
            $rawUser = str_contains($rawTech, '@') ? explode('@', $rawTech)[0] : $rawTech;

            $mapping = collect($techMappings)->first(function ($m) use ($rawTech, $rawUser) {
                $origName = is_object($m) ? ($m->original_name ?? '') : ($m['original_name'] ?? '');
                $orig = strtolower(trim((string) $origName));
                $origUser = str_contains($orig, '@') ? explode('@', $orig)[0] : $orig;

                return $orig === $rawTech
                    || $orig === $rawUser
                    || $origUser === $rawTech
                    || $origUser === $rawUser;
            });

            if ($mapping) {
                $technician = is_object($mapping) ? ($mapping->mapped_name ?? $technician) : ($mapping['mapped_name'] ?? $technician);
            }
        }

        $responseDateRaw = collect([
            data_get($normalized, 'response date'),
            data_get($normalized, 'responded date'),
            data_get($normalized, 'first response date'),
            data_get($normalized, 'initial time'),
            data_get($normalized, 'inisial time'),
        ])->first(fn ($value) => filled($value), '');

        $dueByTimeRaw = collect([
            data_get($normalized, 'due by time'),
            data_get($normalized, 'dueby time'),
            data_get($normalized, 'due date'),
            data_get($normalized, 'resolved due date'),
            data_get($normalized, 'tanggal jatuh tempo'),
            data_get($normalized, 'deadline'),
        ])->first(fn ($value) => filled($value), '');

        $responseDate = DateFormattingService::format($responseDateRaw);
        $dueByTime = DateFormattingService::format($dueByTimeRaw);

        $overdueStatus = collect([
            data_get($normalized, 'overdue status'),
            data_get($normalized, 'resolved overdue'),
            data_get($normalized, 'status overdue'),
            data_get($normalized, 'overdue'),
            data_get($normalized, 'is overdue'),
        ])->first(fn ($value) => filled($value), '');

        $overdueBy = collect([
            data_get($normalized, 'overdue by'),
            data_get($normalized, 'sla violated technician'),
            data_get($normalized, 'fr sla violated technician'),
        ])->first(fn ($value) => filled($value), '');

        $holdTime = collect([
            data_get($normalized, 'hold time'),
            data_get($normalized, 'onhold time'),
            data_get($normalized, 'stopclock'),
        ])->first(fn ($value) => filled($value), '');

        $item = collect([
            data_get($normalized, 'item'),
            data_get($normalized, 'service category'),
            data_get($normalized, 'module'),
            data_get($normalized, 'aplikasi detail'),
            data_get($normalized, 'detail aplikasi'),
        ])->first(fn ($value) => filled($value), '');

        $rawPriority = collect([
            data_get($normalized, 'priority'),
            data_get($normalized, 'prioritas'),
            data_get($normalized, 'severity'),
            data_get($normalized, 'urgency'),
        ])->first(fn ($value) => filled($value), '');
        
        $priority = $this->normalizePriority($rawPriority);

        $category = collect([
            data_get($normalized, 'ticket category'),
            data_get($normalized, 'request type'),
            data_get($normalized, 'category'),
            data_get($normalized, 'kategori'),
        ])->first(fn ($value) => filled($value), '');

        $requestType = collect([
            data_get($normalized, 'request type'),
            data_get($normalized, 'ticket category'),
            data_get($normalized, 'category'),
            data_get($normalized, 'type'),
        ])->first(fn ($value) => filled($value), '');

        $requestStatus = collect([
            data_get($normalized, 'request status'),
            data_get($normalized, 'status'),
            data_get($normalized, 'ticket status'),
        ])->first(fn ($value) => filled($value), '');
        
        $subcategory = collect([
            data_get($normalized, 'subcategory'),
            data_get($normalized, 'subkategori'),
            data_get($normalized, 'account'),
            data_get($normalized, 'tenant'),
            data_get($normalized, 'client'),
            data_get($normalized, 'company'),
        ])->first(fn ($value) => filled($value), '');
        
        $completedTimeRaw = collect([
            data_get($normalized, 'completed time'),
            data_get($normalized, 'completed date'),
            data_get($normalized, 'resolved date'),
            data_get($normalized, 'resolved time'),
        ])->first(fn ($value) => filled($value), '');

        $completedTime = DateFormattingService::format($completedTimeRaw);
        
        $resolvedOverdue = $overdueStatus;
        $resolvedDueDate = $dueByTime;
        
        $group = collect([
            data_get($normalized, 'group'),
            data_get($normalized, 'grup'),
            data_get($normalized, 'team'),
            data_get($normalized, 'department'),
        ])->first(fn ($v) => filled($v), '');
        
        $timeElapsed = collect([
            data_get($normalized, 'time elapsed'),
            data_get($normalized, 'elapsed time'),
            data_get($normalized, 'duration'),
        ])->first(fn ($value) => filled($value), '');
        
        $actualTime = collect([
            data_get($normalized, 'actual time'),
            data_get($normalized, 'time elapsed'),
        ])->first(fn ($value) => filled($value), '');

        $responseOverdue = collect([
            data_get($normalized, 'first response overdue status'),
            data_get($normalized, 'response overdue'),
            data_get($normalized, 'response overdue status'),
        ])->first(fn ($value) => filled($value), '');
        
        $responseDueDateRaw = collect([
            data_get($normalized, 'response dueby time'),
            data_get($normalized, 'response due date'),
            data_get($normalized, 'first response due date'),
        ])->first(fn ($value) => filled($value), '');

        $responseDueDate = DateFormattingService::format($responseDueDateRaw);
        
        $slaResponseTime = data_get($normalized, 'sla response time', '');
        $slaResolvedTime = data_get($normalized, 'sla resolution time', '');

        $aplikasiDetail = collect([
            data_get($normalized, 'aplikasi detail'),
            data_get($normalized, 'detail aplikasi'),
            data_get($normalized, 'account'),
            data_get($normalized, 'tenant'),
            data_get($normalized, 'origin'),
            data_get($normalized, 'aplikasi name'),
            data_get($normalized, 'nama aplikasi'),
            data_get($normalized, 'aplikasi'),
            data_get($normalized, 'subcategory'),
        ])->first(fn ($value) => filled($value), '');

        return [
            'nomor_tiket' => trim((string) $nomorTiket),
            'subject' => trim((string) $subject),
            'status' => $statusMapped,
            'aplikasi' => strtoupper(trim((string) $aplikasi)),
            'aplikasi_detail' => trim((string) $aplikasiDetail),
            'description' => trim((string) $description),
            'requestor_name' => trim((string) $requestorName),
            'resolution' => trim((string) $resolution),
            'created_time' => trim((string) $createdTime),
            'resolved_time' => trim((string) $resolvedTime),
            'email_address' => trim((string) $emailAddress),
            
            // New fields for Custom Fields and Brief mapping
            'technician' => trim((string) $technician),
            'response_date' => trim((string) $responseDate),
            'due_by_time' => trim((string) $dueByTime),
            'overdue_status' => trim((string) $overdueStatus),
            'overdue_by' => trim((string) $overdueBy),
            'hold_time' => trim((string) $holdTime),
            'item' => trim((string) $item),
            'priority' => trim((string) $priority),
            'ticket_category' => trim((string) $category),
            'category' => trim((string) $category),
            
            // Extra fields for DB
            'request_type' => trim((string) $requestType),
            'request_status' => trim((string) $requestStatus),
            'subcategory' => trim((string) $subcategory),
            'completed_time' => trim((string) $completedTime),
            'resolved_overdue' => trim((string) $resolvedOverdue),
            'resolved_due_date' => trim((string) $resolvedDueDate),
            'group' => trim((string) $group),
            'time_elapsed' => trim((string) $timeElapsed),
            'actual_time' => trim((string) $actualTime),
            'response_overdue' => trim((string) $responseOverdue),
            'response_due_date' => trim((string) $responseDueDate),
            'sla_response_time' => trim((string) $slaResponseTime),
            'sla_resolved_time' => trim((string) $slaResolvedTime),
        ];
    }

    public function cleanTiketId(?string $tiketId): string
    {
        if (blank($tiketId)) {
            return '';
        }
        $clean = trim((string) $tiketId);
        $clean = ltrim($clean, '#');
        return strtolower(trim($clean));
    }

    public function normalizePriority(string $priority): string
    {
        $priority = trim($priority);
        if (empty($priority)) return '';

        $upper = strtoupper($priority);
        if (Str::contains($upper, 'HIGH')) {
            return 'HIGH';
        }
        if (Str::contains($upper, 'MEDIUM')) {
            return 'MEDIUM';
        }
        if (Str::contains($upper, 'LOW')) {
            return 'LOW';
        }

        return $priority;
    }

    public function mapTicketCategory(string $category): ?string
    {
        $map = [
            'change request' => '17179b1c-b2d7-434d-bcad-bb90e5280445',
            'check request' => 'bda171c2-58fc-4c38-aa04-34919491ceb1',
            'incident' => '02348973-7f82-48ab-ab3f-6746d9fc1816',
            'proactive monitoring' => '12c66614-7250-4c51-95fc-21f6eb3b8d3f',
            'delivery' => 'bf60adc3-31e0-4505-8135-2509b0417f57',
            'maintenance' => '8c1d16ea-5c22-452b-a83a-c196a64b71e4',
            'information' => 'ffc36a53-d70c-4f40-ac83-9fe9841de6ea',
        ];

        $lower = Str::of($category)->lower()->trim()->squish()->toString();
        
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        foreach ($map as $key => $id) {
            if (str_contains($lower, $key)) {
                return $id;
            }
        }

        return null;
    }

    public function mapAppCategory(string $appName): ?string
    {
        return ClickUpAppRegistry::mapAppCategory($appName);
    }

    private function formatDateString(?string $dateString): string
    {
        return DateFormattingService::format($dateString);
    }
}
