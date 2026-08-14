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
            'results' => [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'details' => [],
            ],
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

        // Preload all cached tasks into an in-memory map keyed by cleanTiketId
        $cachedTasksMap = [];
        $allCached = ClickUpTaskCache::all();
        foreach ($allCached as $ct) {
            $cleanKey = $this->normalizer->cleanTiketId($ct->tiket_id);
            if (filled($cleanKey)) {
                $cachedTasksMap[$cleanKey] = $ct;
            }
        }

        $seenTikets = [];
        $uniqueRows = [];

        foreach ($rows as $row) {
            $normPayload = $this->normalizer->normalizeImportRow($row, $rules, $techMappings, $sourceFormat);
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
                if ($this->isImportCancelled($importToken)) {
                    Cache::put("import_progress_{$importToken}", [
                        'status' => 'cancelled',
                        'processed_rows' => $processed,
                        'total_rows' => $totalRows,
                        'progress_percent' => $totalRows > 0 ? (int) floor($processed / $totalRows * 100) : 100,
                        'results' => $results,
                        'finished_at' => now()->toIso8601String(),
                    ], now()->addHours(6));
                    Log::info("Import cancelled by user for token: {$importToken}");
                    return $results;
                }

                try {
                $payload = $this->normalizer->normalizeImportRow($row, $rules, $techMappings, $sourceFormat);
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

                // Lookup from in-memory cache map first, fallback to DB query if cleanTiket is blank
                $localTask = filled($cleanTiket) && isset($cachedTasksMap[$cleanTiket])
                    ? $cachedTasksMap[$cleanTiket]
                    : ClickUpTaskCache::query()
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
                    $newHash = $this->buildImportHash($payload, $finalBrief);

                    // 1. Fast Hash Match Check
                    if ($localTask->import_hash === $newHash) {
                        $results['skipped']++;
                        $results['details'][] = [
                            'nomor_tiket' => $payload['nomor_tiket'],
                            'aplikasi'    => $payload['aplikasi'],
                            'status'      => 'skipped',
                            'message'     => 'Data tidak berubah sejak import terakhir (skip).',
                        ];
                        continue;
                    }

                    // 2. Local DB Field-by-Field Diff Evaluator
                    $newName   = $this->buildTaskName($payload);
                    $newStatus = $payload['status'];
                    $payload['name'] = $newName;

                    $nameDiff   = $this->isFieldDifferent($newName, $localTask->name);
                    $statusDiff = strtolower(trim($newStatus)) !== strtolower(trim((string) $localTask->status));

                    $hasMainTaskDiff = $nameDiff || $statusDiff;

                    // Evaluate Custom Field Diffs against DB local
                    $customFieldValues = [];

                    if ($this->isFieldDifferent($finalBrief, $localTask->description)) {
                        $customFieldValues['ca78bfeb-c360-45b0-9cb4-bf6e90db5b30'] = $finalBrief;
                    }

                    if ($this->isFieldDifferent($payload['requestor_name'], $localTask->requestor_name)) {
                        $customFieldValues['b703d753-adc4-406e-a01b-d0b581cf66cd'] = $payload['requestor_name'];
                    }

                    if ($this->isFieldDifferent($payload['resolution'], $localTask->resolution)) {
                        $customFieldValues['c155dabd-5a8e-4409-8bd9-bec1c2e79ec8'] = $payload['resolution'];
                    }

                    if ($this->isFieldDifferent($payload['created_time'], $localTask->created_time)) {
                        $customFieldValues['7b24c557-4735-4afc-a239-58347dd1a2e3'] = $payload['created_time'];
                    }

                    if ($this->isFieldDifferent($payload['resolved_time'], $localTask->resolved_time)) {
                        $customFieldValues['b3f49b69-3095-4687-8b34-ea2fddd95cea'] = $payload['resolved_time'];
                    }

                    if ($this->isFieldDifferent($payload['nomor_tiket'], $localTask->tiket_id)) {
                        $customFieldValues['b8c71da9-681b-4418-80e5-9dae2565e70a'] = $payload['nomor_tiket'];
                    }

                    $rawCat = $payload['ticket_category'] ?? $payload['category'] ?? data_get($row, 'category') ?? data_get($row, 'ticket_category') ?? data_get($row, 'request type') ?? '';
                    if (filled($rawCat) && $this->isFieldDifferent($rawCat, $localTask->category)) {
                        $categoryId = $this->normalizer->mapTicketCategory($rawCat);
                        if ($categoryId) {
                            $customFieldValues['ac661cf6-6078-4c36-b5e3-da7c74ddf7a8'] = $categoryId;
                        }
                    }

                    if (filled($payload['aplikasi']) && $appId && $this->isFieldDifferent($payload['aplikasi'], $localTask->aplikasi)) {
                        $customFieldValues[ClickUpAppRegistry::FIELD_ID] = $appId;
                    }

                    $totalChanges = ($hasMainTaskDiff ? 1 : 0) + count($customFieldValues);

                    // 3. If zero fields differ between local DB and Excel, skip ClickUp API calls completely!
                    if ($totalChanges === 0) {
                        $updatedRecord = $this->syncService->upsertCacheFromRemoteTask(['id' => $localTask->clickup_task_id], $module->module_name, $payload);
                        if ($updatedRecord) {
                            $updatedRecord->updateQuietly(['import_hash' => $newHash]);
                            if (filled($cleanTiket)) {
                                $cachedTasksMap[$cleanTiket] = $updatedRecord;
                            }
                        } else {
                            $localTask->updateQuietly(['import_hash' => $newHash]);
                            if (filled($cleanTiket)) {
                                $cachedTasksMap[$cleanTiket] = $localTask;
                            }
                        }

                        $results['skipped']++;
                        $results['details'][] = [
                            'nomor_tiket' => $payload['nomor_tiket'],
                            'aplikasi'    => $payload['aplikasi'],
                            'status'      => 'skipped',
                            'message'     => 'Data di DB lokal dan Excel sama persis (skip API call).',
                        ];
                        continue;
                    }

                    // 4. Update Main Task properties ONLY if Name or Status changed
                    $remoteTaskData = [
                        'id'     => $localTask->clickup_task_id,
                        'name'   => $newName,
                        'status' => $newStatus,
                    ];

                    if ($hasMainTaskDiff) {
                        $updatePayload = [];
                        if ($nameDiff)   $updatePayload['name']   = $newName;
                        if ($statusDiff) $updatePayload['status'] = $newStatus;

                        $response = $this->apiClient->requestWithRetry(
                            fn () => $this->apiClient->client()->put("/task/{$localTask->clickup_task_id}", $updatePayload)
                        );

                        if ($response->failed()) {
                            $results['failed']++;
                            $results['details'][] = [
                                'nomor_tiket' => $payload['nomor_tiket'],
                                'aplikasi'    => $payload['aplikasi'],
                                'status'      => 'failed',
                                'message'     => $response->json('err') ?? $response->body(),
                            ];
                            continue;
                        }

                        if (is_array($response->json())) {
                            $remoteTaskData = $response->json();
                        }
                    }

                    // 5. Update ONLY custom fields that actually changed via ClickUp POST /task/{id}/field/{field_id}
                    foreach ($customFieldValues as $fieldId => $val) {
                        if (filled($val)) {
                            $this->apiClient->requestWithRetry(
                                fn () => $this->apiClient->client()->post("/task/{$localTask->clickup_task_id}/field/{$fieldId}", ['value' => $val])
                            );
                        }
                    }

                    // 6. Persist DB Cache & Hash
                    $updatedRecord = $this->syncService->upsertCacheFromRemoteTask($remoteTaskData, $module->module_name, $payload);
                    if ($updatedRecord) {
                        $updatedRecord->updateQuietly(['import_hash' => $newHash]);
                        if (filled($cleanTiket)) {
                            $cachedTasksMap[$cleanTiket] = $updatedRecord;
                        }
                    }

                    $results['updated']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'],
                        'aplikasi'    => $payload['aplikasi'],
                        'status'      => 'updated',
                        'message'     => "Updated {$totalChanges} field di ClickUp.",
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

                if (filled($payload['created_time'])) {
                    $taskPayload['custom_fields'][] = [
                        'id' => '7b24c557-4735-4afc-a239-58347dd1a2e3',
                        'value' => $payload['created_time'],
                    ];
                }

                if (filled($payload['resolved_time'])) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'b3f49b69-3095-4687-8b34-ea2fddd95cea',
                        'value' => $payload['resolved_time'],
                    ];
                }

                if (filled($payload['nomor_tiket'])) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'b8c71da9-681b-4418-80e5-9dae2565e70a',
                        'value' => $payload['nomor_tiket'],
                    ];
                }

                $rawCat = $payload['ticket_category'] ?? $payload['category'] ?? data_get($row, 'category') ?? data_get($row, 'ticket_category') ?? data_get($row, 'request type') ?? '';
                if (filled($rawCat)) {
                    $categoryId = $this->normalizer->mapTicketCategory($rawCat);
                    if ($categoryId) {
                        $taskPayload['custom_fields'][] = [
                            'id' => 'ac661cf6-6078-4c36-b5e3-da7c74ddf7a8',
                            'value' => $categoryId,
                        ];
                    }
                }

                if (filled($payload['aplikasi']) && $appId) {
                    $taskPayload['custom_fields'][] = [
                        'id' => ClickUpAppRegistry::FIELD_ID,
                        'value' => $appId,
                    ];
                }

                // Resolve dynamic ClickUp Assignees (Dzaka, Mukhlis, Support, etc.)
                $assigneeEvaluator = new Routing\AssigneeEvaluatorService();
                $assigneeIds = $assigneeEvaluator->resolveAssignees($payload['aplikasi'] ?? '', $row);
                if (!empty($assigneeIds)) {
                    $taskPayload['assignees'] = $assigneeIds;
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

                $createdRecord = $this->syncService->upsertCacheFromRemoteTask($response->json(), $module->module_name, $payload);
                // Persist hash so next import with the same data skips this ticket entirely
                if ($createdRecord) {
                    $createdRecord->updateQuietly(['import_hash' => $this->buildImportHash($payload, $finalBrief)]);
                }
                $results['created']++;
                $results['details'][] = [
                    'nomor_tiket' => $payload['nomor_tiket'],
                    'aplikasi'    => $payload['aplikasi'],
                    'status'      => 'created',
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
                        'results' => $results,
                    ], now()->addHours(6));
                }
            }
        }
    } catch (\Throwable $e) {
            Log::error("ClickUp Import Error: " . $e->getMessage());
        } finally {
            if ($importToken) {
                $progress = Cache::get("import_progress_{$importToken}", []);
                $currentStatus = data_get($progress, 'status');
                $finalStatus = ($currentStatus === 'cancelled') ? 'cancelled' : 'completed';

                Cache::put("import_progress_{$importToken}", [
                    'import_token' => $importToken,
                    'status' => $finalStatus,
                    'processed_rows' => $processed,
                    'total_rows' => max($totalRows, $processed),
                    'progress_percent' => 100,
                    'results' => $results,
                    'finished_at' => now()->toIso8601String(),
                ], now()->addHours(6));
            }

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
            'results' => [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'failed' => 0,
                'details' => [],
            ],
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
            $payload = $this->normalizer->normalizeImportRow($row, $rules, $techMappings, $sourceFormat);

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

    /**
     * Build a stable MD5 fingerprint of the import payload.
     * Used to detect whether a ticket has changed since the last import
     * so we can skip all API calls for unchanged tickets.
     */
    private function buildImportHash(array $payload, string $finalBrief): string
    {
        return md5(implode('|', [
            $this->buildTaskName($payload),
            $payload['status'] ?? '',
            $payload['requestor_name'] ?? '',
            $payload['resolution'] ?? '',
            $finalBrief,
            $payload['created_time'] ?? '',
            $payload['resolved_time'] ?? '',
            $payload['nomor_tiket'] ?? '',
            $payload['aplikasi'] ?? '',
            $payload['technician'] ?? '',
            $payload['due_by_time'] ?? '',
            $payload['overdue_status'] ?? '',
            $payload['item'] ?? '',
            $payload['priority'] ?? '',
        ]));
    }

    /**
     * Helper to compare a new incoming string value from Excel with current local DB value.
     * Returns true ONLY if the new value is filled and differs from local DB value.
     */
    private function isFieldDifferent(?string $newValue, ?string $currentValue): bool
    {
        if (blank($newValue)) {
            return false;
        }

        $newStr  = trim((string) $newValue);
        $currStr = trim((string) $currentValue);

        if ($newStr === '') {
            return false;
        }

        $newNorm  = str_replace(["\r\n", "\r"], "\n", $newStr);
        $currNorm = str_replace(["\r\n", "\r"], "\n", $currStr);

        return $newNorm !== $currNorm;
    }

    public function isImportCancelled(?string $importToken = null): bool
    {
        if (blank($importToken)) {
            return false;
        }

        if (Cache::get("clickup:import:cancel:{$importToken}")) {
            return true;
        }

        $progress = Cache::get("import_progress_{$importToken}");
        return data_get($progress, 'status') === 'cancelled';
    }

    public function cancelImport(?string $importToken = null): array
    {
        if (blank($importToken)) {
            $importToken = Cache::get(self::IMPORT_LOCK_KEY);
        }

        if (filled($importToken)) {
            Cache::put("clickup:import:cancel:{$importToken}", true, now()->addHours(1));

            $progressKey = "import_progress_{$importToken}";
            $progress = Cache::get($progressKey, []);
            if (is_array($progress)) {
                $progress['status'] = 'cancelled';
                $progress['finished_at'] = now()->toIso8601String();
                Cache::put($progressKey, $progress, now()->addHours(6));
            }
        }

        Cache::forget(self::IMPORT_LOCK_KEY);

        return [
            'status' => 'cancelled',
            'import_token' => $importToken,
            'message' => 'Proses import berhasil dihentikan.',
        ];
    }
}
