<?php

namespace App\Services\ClickUp;

use App\Models\ClickUpImportRule;
use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use App\Models\TechnicianMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClickUpImportService
{
    private const IMPORT_LOCK_KEY = 'clickup:import_active_lock';

    public function __construct(
        private readonly ClickUpApiClient $apiClient,
        private readonly ImportNormalizerService $normalizer,
        private readonly ClickUpSyncService $syncService
    ) {
    }

    public function startImport(array $rows, string $sourceFormat = 'ebesha', ?string $importToken = null): array
    {
        if (Cache::has(self::IMPORT_LOCK_KEY)) {
            $existingToken = Cache::get(self::IMPORT_LOCK_KEY);
            $progress = Cache::get("import_progress_{$existingToken}");

            if (!$progress || in_array($progress['status'] ?? '', ['completed', 'failed', 'missing', 'not_found', 'done']) || ($importToken && $existingToken === $importToken)) {
                Cache::forget(self::IMPORT_LOCK_KEY);
            } else {
                return [
                    'status' => 'already_running',
                    'import_token' => $existingToken,
                    'message' => 'Proses import sedang berjalan pada tab atau perangkat lain.',
                ];
            }
        }

        $importToken = $importToken ?: (string) Str::uuid();
        
        Cache::put(self::IMPORT_LOCK_KEY, $importToken, now()->addHours(6));

        Cache::put("import_progress_{$importToken}", [
            'import_token' => $importToken,
            'status' => 'running',
            'processed_rows' => 0,
            'total_rows' => count($rows),
            'progress_percent' => 0,
        ], now()->addHours(6));

        return [
            'status' => 'started',
            'import_token' => $importToken,
            'message' => 'Proses import telah dimulai di latar belakang.',
            'rows' => $rows, // we return these so controller can pass them to defer
            'source_format' => $sourceFormat,
        ];
    }

    public function runImport(array $rows, string $sourceFormat, string $importToken): array
    {
        set_time_limit(0);

        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $rules = ClickUpImportRule::where('source_format', $sourceFormat)->get();
        $techMappings = TechnicianMapping::all();

        $seenTikets = [];
        $uniqueRows = [];

        foreach ($rows as $row) {
            $normPayload = $this->normalizer->normalizeImportRow($row, $rules, $techMappings);
            $rawTiket = trim((string) ($normPayload['nomor_tiket'] ?? ''));
            $cleanKey = $this->normalizer->cleanTiketId($rawTiket);

            if (filled($cleanKey)) {
                if (isset($seenTikets[$cleanKey])) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'nomor_tiket' => $rawTiket,
                        'aplikasi' => $normPayload['aplikasi'],
                        'status' => 'skipped',
                        'message' => 'Data duplikat dalam file import (di-skip agar unik).',
                    ];
                    continue;
                }
                $seenTikets[$cleanKey] = true;
            }

            $uniqueRows[] = $row;
        }

        $rows = $uniqueRows;
        $totalRows = count($rows);
        $processed = 0;

        try {
            foreach ($rows as $row) {
                // Check if lock was lost
                if (!Cache::has(self::IMPORT_LOCK_KEY) || Cache::get(self::IMPORT_LOCK_KEY) !== $importToken) {
                    Log::warning("Import lock lost for token: {$importToken}. Aborting.");
                    break;
                }

                try {
                $payload = $this->normalizer->normalizeImportRow($row, $rules, $techMappings);
                $payload['generate'] = strtoupper(trim($sourceFormat));

                if (blank($payload['nomor_tiket'])) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'] ?? 'N/A',
                        'aplikasi' => $payload['aplikasi'] ?? 'N/A',
                        'status' => 'skipped',
                        'message' => 'Nomor tiket kosong.',
                    ];
                    continue;
                }

                $appId = $this->normalizer->mapAppCategory($payload['aplikasi']);

                if (filled($payload['aplikasi']) && !$appId) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'],
                        'aplikasi' => $payload['aplikasi'],
                        'status' => 'skipped',
                        'message' => 'Nama aplikasi tidak valid/di-skip.',
                    ];
                    continue;
                }

                $module = ClickUpModule::query()->where('is_active', true)->first();

                if (! $module || blank($module->clickup_list_id)) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'],
                        'aplikasi' => $payload['aplikasi'],
                        'status' => 'skipped',
                        'message' => 'Sistem belum memiliki Module aktif dengan List ID yang dikonfigurasi.',
                    ];
                    continue;
                }

                $cleanTiket = $this->normalizer->cleanTiketId($payload['nomor_tiket']);

                $localTask = ClickUpTaskCache::query()
                    ->where(function ($q) use ($payload, $cleanTiket) {
                        $q->where('tiket_id', $payload['nomor_tiket'])
                          ->orWhere('tiket_id', $cleanTiket)
                          ->orWhere('tiket_id', '#' . $cleanTiket);
                    })
                    ->first();

                $briefParts = [];
                $briefParts[] = "Technician: " . ($payload['technician'] ?: '-');
                $briefParts[] = "First Response: " . ($payload['response_date'] ?: '-');
                $briefParts[] = "Tanggal Tenggat Tiket SLA: " . ($payload['due_by_time'] ?: '-');
                $briefParts[] = "Overdue Breach: " . ($payload['overdue_status'] ?: '-');
                $briefParts[] = "Overdue Sama Siapa: " . ($payload['overdue_by'] ?: '-');
                $briefParts[] = "Di Stopclock: " . ($payload['hold_time'] ?: '-');
                $briefParts[] = "Item: " . ($payload['item'] ?: '-');
                $briefParts[] = "Priority: " . ($payload['priority'] ?: '-');

                if (filled($payload['description'])) {
                    $briefParts[] = "\n" . $payload['description'];
                }
                $finalBrief = implode("\n", $briefParts);

                if ($localTask) {
                    $updatePayload = [
                        'name' => $this->buildTaskName($payload),
                        'status' => $payload['status'],
                    ];

                    $response = $this->apiClient->requestWithRetry(
                        fn () => $this->apiClient->client()->put("/task/{$localTask->clickup_task_id}", $updatePayload)
                    );

                    if ($response->failed()) {
                        $results['failed']++;
                        $results['details'][] = [
                            'nomor_tiket' => $payload['nomor_tiket'],
                            'aplikasi' => $payload['aplikasi'],
                            'status' => 'failed',
                            'message' => $response->json('err') ?? $response->body(),
                        ];
                        continue;
                    }

                    // Update custom fields individually via ClickUp V2 API
                    $customFieldValues = [
                        'ca78bfeb-c360-45b0-9cb4-bf6e90db5b30' => $finalBrief,
                        'b703d753-adc4-406e-a01b-d0b581cf66cd' => $payload['requestor_name'],
                        'c155dabd-5a8e-4409-8bd9-bec1c2e79ec8' => $payload['resolution'],
                    ];

                    if (filled($payload['ticket_category'])) {
                        $categoryId = $this->normalizer->mapTicketCategory($payload['ticket_category']);
                        if ($categoryId) {
                            $customFieldValues['ac661cf6-6078-4c36-b5e3-da7c74ddf7a8'] = $categoryId;
                        }
                    }

                    if (filled($payload['aplikasi']) && $appId) {
                        $customFieldValues['aec0cf66-4c70-41e1-9b61-311d4d1a8eb5'] = $appId;
                    }

                    foreach ($customFieldValues as $fieldId => $val) {
                        if (filled($val)) {
                            $this->apiClient->requestWithRetry(
                                fn () => $this->apiClient->client()->post("/task/{$localTask->clickup_task_id}/field/{$fieldId}", ['value' => $val])
                            );
                        }
                    }

                    $remoteTaskData = $response->json();
                    if (is_array($remoteTaskData) && blank(data_get($remoteTaskData, 'id'))) {
                        $remoteTaskData['id'] = $localTask->clickup_task_id;
                    }
                    $this->syncService->upsertCacheFromRemoteTask($remoteTaskData, $module->module_name, $payload);
                    $results['updated']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'],
                        'aplikasi' => $payload['aplikasi'],
                        'status' => 'updated',
                    ];
                    continue;
                }

                $taskPayload = [
                    'name' => $this->buildTaskName($payload),
                    'status' => $payload['status'],
                    'custom_fields' => [],
                ];

                if (filled($finalBrief)) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'ca78bfeb-c360-45b0-9cb4-bf6e90db5b30',
                        'value' => $finalBrief,
                    ];
                }

                if (filled($payload['requestor_name'])) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'b703d753-adc4-406e-a01b-d0b581cf66cd',
                        'value' => $payload['requestor_name'],
                    ];
                }

                if (filled($payload['resolution'])) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'c155dabd-5a8e-4409-8bd9-bec1c2e79ec8',
                        'value' => $payload['resolution'],
                    ];
                }

                if (filled($payload['ticket_category'])) {
                    $categoryId = $this->normalizer->mapTicketCategory($payload['ticket_category']);
                    if ($categoryId) {
                        $taskPayload['custom_fields'][] = [
                            'id' => 'ac661cf6-6078-4c36-b5e3-da7c74ddf7a8',
                            'value' => $categoryId,
                        ];
                    }
                }

                if (filled($payload['aplikasi']) && $appId) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'aec0cf66-4c70-41e1-9b61-311d4d1a8eb5',
                        'value' => $appId,
                    ];
                }

                $response = $this->apiClient->requestWithRetry(fn () => $this->apiClient->client()->post("/list/{$module->clickup_list_id}/task", $taskPayload));

                if ($response->failed() && str_contains(strtolower($response->body()), 'cannot find the custom field')) {
                    $fallbackPayload = [
                        'name' => $this->buildTaskName($payload),
                        'status' => $payload['status'],
                    ];
                    $response = $this->apiClient->requestWithRetry(fn () => $this->apiClient->client()->post("/list/{$module->clickup_list_id}/task", $fallbackPayload));
                }

                if ($response->failed()) {
                    $results['failed']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'],
                        'aplikasi' => $payload['aplikasi'],
                        'status' => 'failed',
                        'message' => $response->json('err') ?? $response->body(),
                    ];
                    continue;
                }

                $this->syncService->upsertCacheFromRemoteTask($response->json(), $module->module_name, $payload);
                $results['created']++;
                $results['details'][] = [
                    'nomor_tiket' => $payload['nomor_tiket'],
                    'aplikasi' => $payload['aplikasi'],
                    'status' => 'created',
                ];
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['details'][] = [
                    'nomor_tiket' => data_get($row, 'nomor_tiket', 'N/A'),
                    'aplikasi' => data_get($row, 'aplikasi', 'N/A'),
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            } finally {
                $processed++;
                if ($importToken) {
                    Cache::put("import_progress_{$importToken}", [
                        'import_token' => $importToken,
                        'status' => $processed >= $totalRows ? 'completed' : 'running',
                        'processed_rows' => $processed,
                        'total_rows' => $totalRows,
                        'progress_percent' => $totalRows > 0 ? (int) round(($processed / $totalRows) * 100) : 100,
                    ], now()->addHours(1));
                }
            }
        }
    } catch (\Throwable $e) {
            Log::error("ClickUp Import Error: " . $e->getMessage());
        } finally {
            if (Cache::get(self::IMPORT_LOCK_KEY) === $importToken) {
                Cache::forget(self::IMPORT_LOCK_KEY);
            }
        }

        return $results;
    }

    public function importProgress(string $importToken): array
    {
        return Cache::get("import_progress_{$importToken}", [
            'import_token' => $importToken,
            'status' => 'not_found',
            'processed_rows' => 0,
            'total_rows' => 0,
            'progress_percent' => 0,
        ]);
    }

    public function previewImportRows(array $rows, string $sourceFormat = 'ebesha'): array
    {
        $rules = ClickUpImportRule::where('source_format', $sourceFormat)->get();
        $techMappings = TechnicianMapping::all();
        $modules = ClickUpModule::all()->keyBy('module_name');

        $cachedTiketIds = ClickUpTaskCache::query()
            ->whereNotNull('tiket_id')
            ->pluck('tiket_id')
            ->map(fn($id) => $this->normalizer->cleanTiketId($id))
            ->filter()
            ->flip()
            ->toArray();

        $previewRows = [];
        $seenInFile = [];

        foreach ($rows as $row) {
            $payload = $this->normalizer->normalizeImportRow($row, $rules, $techMappings);

            if (blank($payload['nomor_tiket'])) {
                continue;
            }

            $payload['generate'] = strtoupper(trim($sourceFormat));

            $issues = [];
            $primaryModule = $modules->where('is_active', true)->first();
            $cleanTiket = $this->normalizer->cleanTiketId($payload['nomor_tiket']);

            $isDuplicateInFile = false;
            if (filled($cleanTiket)) {
                if (isset($seenInFile[$cleanTiket])) {
                    $isDuplicateInFile = true;
                } else {
                    $seenInFile[$cleanTiket] = true;
                }
            }

            $isDuplicateCache = filled($cleanTiket) && isset($cachedTiketIds[$cleanTiket]);
            $isDuplicate = $isDuplicateCache || $isDuplicateInFile;

            if (filled($payload['aplikasi']) && !$this->normalizer->mapAppCategory($payload['aplikasi'])) {
                $issues[] = 'Nama aplikasi (' . $payload['aplikasi'] . ') tidak ditemukan di daftar opsi dropdown Apps';
            }

            if (! $primaryModule) {
                $issues[] = 'Belum ada Module aktif yang terkonfigurasi di sistem (harap buat minimal 1 module)';
            } elseif (blank($primaryModule->clickup_list_id)) {
                $issues[] = 'List ID module belum tersimpan, akan di-resolve otomatis saat submit';
            }

            if ($isDuplicateInFile) {
                $issues[] = 'Tiket duplikat dalam file Excel (hanya 1 yang diproses)';
            } elseif ($isDuplicateCache) {
                $issues[] = 'Tiket sudah ada di cache (akan di-update)';
            }

            $status = ! $primaryModule || (filled($payload['aplikasi']) && !$this->normalizer->mapAppCategory($payload['aplikasi']))
                ? 'skip'
                : ($isDuplicateInFile
                    ? 'skip'
                    : ($isDuplicateCache
                        ? 'duplicate'
                        : ($primaryModule->clickup_list_id
                            ? 'ready'
                            : 'warn')));

            $payload['is_duplicate'] = $isDuplicate;
            $payload['review_status'] = $status;
            $payload['review_reason'] = empty($issues) ? 'Siap di-submit (baru)' : implode(', ', $issues);

            $previewRows[] = $payload;
        }

        return [
            'total' => count($previewRows),
            'rows' => $previewRows,
            'headers' => count($rows) > 0 ? array_keys($rows[0]) : [],
        ];
    }

    private function buildTaskName(array $payload): string
    {
        $nomorTiket = $payload['nomor_tiket'];
        $prefix = ctype_digit($nomorTiket) ? '#' : '';
        $taskName = $prefix . $nomorTiket;

        if ($payload['subject'] !== '') {
            $taskName .= ' ' . $payload['subject'];
        }

        return $taskName;
    }
}
