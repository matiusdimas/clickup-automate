<?php

namespace App\Services;

use App\Models\ClickUpImportRule;
use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ClickUpService
{
    private const BASE_URL = 'https://api.clickup.com/api/v2';

    public function overview(): array
    {
        $modules = ClickUpModule::query()
            ->orderBy('module_name')
            ->get()
            ->map(fn (ClickUpModule $module) => $this->modulePayload($module))
            ->values();

        $recentTasks = ClickUpTaskCache::query()
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->map(fn (ClickUpTaskCache $task) => $this->taskPayload($task))
            ->values();

        $lastSyncedAt = ClickUpModule::query()->whereNotNull('last_synced_at')->max('last_synced_at');

        return [
            'summary' => [
                'module_count' => $modules->count(),
                'active_module_count' => $modules->where('is_active', true)->count(),
                'task_count' => ClickUpTaskCache::count(),
                'last_synced_at' => $lastSyncedAt ? Carbon::parse($lastSyncedAt)->toIso8601String() : null,
            ],
            'modules' => $modules,
            'recent_tasks' => $recentTasks,
        ];
    }

    public function syncProgress(string $syncToken): array
    {
        return Cache::get($this->progressKey($syncToken), [
            'sync_token' => $syncToken,
            'status' => 'missing',
            'summary' => [
                'total_modules' => 0,
                'completed_modules' => 0,
                'fetched_tasks' => 0,
                'cached_tasks' => 0,
                'progress_percent' => 0,
            ],
            'modules' => [],
        ]);
    }

    public function syncAll(?string $syncToken = null): array
    {
        set_time_limit(0);

        $apiKey = config('services.clickup.api_key');

        if (! $apiKey) {
            throw new RuntimeException('CLICKUP_API_KEY belum diatur di config/services.php.');
        }

        $syncToken = $syncToken ?: (string) Str::uuid();
        $modules = ClickUpModule::query()->where('is_active', true)->get();

        $progress = $this->initializeSyncProgress($syncToken, $modules);
        $moduleStates = collect($progress['modules'])->keyBy('module_name')->all();
        $cachedTasks = 0;
        $fetchedTasks = 0;

        while (true) {
            $activeModules = collect($moduleStates)
                ->filter(fn (array $moduleState) => ! $moduleState['done'] && (filled($moduleState['clickup_list_id']) || filled($moduleState['clickup_view_id'])))
                ->all();

            if (empty($activeModules)) {
                break;
            }

            $moduleOrder = array_keys($activeModules);

            $responses = $this->client($apiKey)->pool(function (Pool $pool) use ($activeModules, $apiKey) {
                $requests = [];

                foreach ($activeModules as $moduleName => $moduleState) {
                    $endpoint = filled($moduleState['clickup_list_id'] ?? null)
                        ? self::BASE_URL . "/list/{$moduleState['clickup_list_id']}/task"
                        : self::BASE_URL . "/view/{$moduleState['clickup_view_id']}/task";

                    $requests[] = $pool
                        ->withHeaders([
                            'Authorization' => $apiKey,
                            'Content-Type' => 'application/json',
                        ])
                        ->withOptions([
                            'verify' => false,
                        ])
                        ->get($endpoint, [
                            'page' => $moduleState['page'],
                            'include_closed' => 'true',
                        ]);
                }

                return $requests;
            });

            foreach ($moduleOrder as $index => $moduleName) {
                $response = $responses[$index] ?? null;

                if (! $response) {
                    $moduleState = $moduleStates[$moduleName];
                    $moduleState['status'] = 'failed';
                    $moduleState['error'] = 'Respon ClickUp kosong untuk batch ini.';
                    $moduleState['done'] = true;
                    $moduleState['completed_at'] = now()->toIso8601String();
                    $moduleStates[$moduleName] = $moduleState;
                    $progress = $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks);
                    continue;
                }

                $moduleState = $moduleStates[$moduleName];
                $moduleModel = ClickUpModule::query()->where('module_name', $moduleState['module_name'])->first();

                if ($response instanceof ConnectionException) {
                    $moduleState['status'] = 'failed';
                    $moduleState['error'] = $response->getMessage();
                    $moduleState['done'] = true;
                    $moduleState['completed_at'] = now()->toIso8601String();
                    $moduleStates[$moduleName] = $moduleState;
                    $progress = $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks);
                    continue;
                }

                if ($response->failed()) {
                    $moduleState['status'] = 'failed';
                    $moduleState['error'] = $response->json('err') ?? $response->body();
                    $moduleState['done'] = true;
                    $moduleState['completed_at'] = now()->toIso8601String();
                    $moduleStates[$moduleName] = $moduleState;
                    $progress = $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks);
                    continue;
                }

                $tasks = $response->json('tasks', []);

                if (empty($tasks)) {
                    $moduleState['done'] = true;
                    $moduleState['status'] = 'done';
                    $moduleState['completed_at'] = now()->toIso8601String();
                    $moduleStates[$moduleName] = $moduleState;
                    $progress = $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks);
                    continue;
                }

                foreach ($tasks as $task) {
                    $this->upsertCacheFromRemoteTask($task, $moduleState['module_name']);
                    $this->syncModuleListIdFromTask($moduleModel, $task);
                    $cachedTasks++;
                }

                $moduleState['pages']++;
                $moduleState['fetched'] += count($tasks);
                $moduleState['cached'] = $moduleState['fetched'];
                $moduleState['page']++;
                $fetchedTasks += count($tasks);

                if ((bool) data_get($response->json(), 'last_page', false)) {
                    $moduleState['done'] = true;
                    $moduleState['status'] = 'done';
                    $moduleState['completed_at'] = now()->toIso8601String();
                    if ($moduleModel) {
                        $moduleModel->forceFill([
                            'last_synced_at' => now(),
                        ])->save();
                    }
                } else {
                    $moduleState['status'] = 'running';
                }

                $moduleStates[$moduleName] = $moduleState;
                $progress = $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks);
            }
        }

        $finalModules = array_values($moduleStates);
        $finishedProgress = $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks, 'done');

        return [
            'message' => 'Sinkronisasi selesai.',
            'sync_token' => $syncToken,
            'summary' => [
                'module_count' => $modules->count(),
                'fetched_tasks' => $fetchedTasks,
                'cached_tasks' => $cachedTasks,
            ],
            'modules' => $finalModules,
            'progress' => $finishedProgress,
        ];
    }

    public function importRows(array $rows, string $sourceFormat = 'ebesha', ?string $importToken = null): array
    {
        set_time_limit(0);

        $apiKey = config('services.clickup.api_key');

        if (! $apiKey) {
            throw new RuntimeException('CLICKUP_API_KEY belum diatur di config/services.php.');
        }

        $totalRows = count($rows);
        $processed = 0;

        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $rules = ClickUpImportRule::where('source_format', $sourceFormat)->get();
        $techMappings = \App\Models\TechnicianMapping::all();

        foreach ($rows as $row) {
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

            $payload = $this->normalizeImportRow($row, $rules, $techMappings);
            $payload['generate'] = strtoupper(trim($sourceFormat));

            if (blank($payload['nomor_tiket'])) {
                $results['skipped']++;
                $results['details'][] = [
                    'nomor_tiket' => $payload['nomor_tiket'],
                    'aplikasi' => $payload['aplikasi'],
                    'status' => 'skipped',
                    'message' => 'Nomor tiket kosong.',
                ];
                continue;
            }

            $appId = $this->mapAppCategory($payload['aplikasi']);

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

            // Always use the primary/first configured active module since we are consolidating to 1 list
            $module = ClickUpModule::query()->where('is_active', true)->first();

            if (! $module) {
                $results['skipped']++;
                $results['details'][] = [
                    'nomor_tiket' => $payload['nomor_tiket'],
                    'aplikasi' => $payload['aplikasi'],
                    'status' => 'skipped',
                    'message' => 'Sistem belum memiliki Module aktif yang dikonfigurasi. Harap buat minimal 1 module di Dashboard.',
                ];
                continue;
            }

            $localTask = ClickUpTaskCache::query()
                ->where('tipe_aplikasi', $payload['aplikasi'])
                ->where('tiket_id', $payload['nomor_tiket'])
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
                $response = $this->client($apiKey)->put("/task/{$localTask->clickup_task_id}", [
                    'name' => $this->buildTaskName($payload),
                    'status' => $payload['status'],
                ]);

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

                // Insert Custom Fields in ClickUp unconditionally to keep them perfectly synced with Excel
                if (filled($finalBrief)) {
                    $this->client($apiKey)->post("/task/{$localTask->clickup_task_id}/field/ca78bfeb-c360-45b0-9cb4-bf6e90db5b30", [
                        'value' => $finalBrief,
                    ]);
                }

                if (filled($payload['requestor_name'])) {
                    $this->client($apiKey)->post("/task/{$localTask->clickup_task_id}/field/b703d753-adc4-406e-a01b-d0b581cf66cd", [
                        'value' => $payload['requestor_name'],
                    ]);
                }

                if (filled($payload['resolution'])) {
                    $this->client($apiKey)->post("/task/{$localTask->clickup_task_id}/field/c155dabd-5a8e-4409-8bd9-bec1c2e79ec8", [
                        'value' => $payload['resolution'],
                    ]);
                }

                if (filled($payload['created_time'])) {
                    $this->client($apiKey)->post("/task/{$localTask->clickup_task_id}/field/7b24c557-4735-4afc-a239-58347dd1a2e3", [
                        'value' => $payload['created_time'],
                    ]);
                }

                if (filled($payload['resolved_time'])) {
                    $this->client($apiKey)->post("/task/{$localTask->clickup_task_id}/field/b3f49b69-3095-4687-8b34-ea2fddd95cea", [
                        'value' => $payload['resolved_time'],
                    ]);
                }

                if (filled($payload['nomor_tiket'])) {
                    $this->client($apiKey)->post("/task/{$localTask->clickup_task_id}/field/b8c71da9-681b-4418-80e5-9dae2565e70a", [
                        'value' => $payload['nomor_tiket'],
                    ]);
                }

                $updateFields = [];

                if (filled($payload['email_address'])) {
                    $updateFields[] = [
                        'id' => 'f1ddf2e6-ff4f-4b2d-9bac-28145776bdae',
                        'value' => $payload['email_address'],
                    ];
                }

                if (filled($payload['ticket_category'])) {
                    $categoryId = $this->mapTicketCategory($payload['ticket_category']);
                    if ($categoryId) {
                        $updateFields[] = [
                            'id' => 'ac661cf6-6078-4c36-b5e3-da7c74ddf7a8',
                            'value' => $categoryId,
                        ];
                    }
                }

                if (filled($payload['created_time'])) {
                    $updateFields[] = [
                        'id' => '7b24c557-4735-4afc-a239-58347dd1a2e3',
                        'value' => $payload['created_time'],
                    ];
                }

                if (filled($payload['resolved_time'])) {
                    $updateFields[] = [
                        'id' => 'b3f49b69-3095-4687-8b34-ea2fddd95cea',
                        'value' => $payload['resolved_time'],
                    ];
                }

                if (filled($payload['resolution'])) {
                    $updateFields[] = [
                        'id' => 'c155dabd-5a8e-4409-8bd9-bec1c2e79ec8',
                        'value' => $payload['resolution'],
                    ];
                }

                if (filled($payload['aplikasi'])) {
                    $appId = $this->mapAppCategory($payload['aplikasi']);
                    if ($appId) {
                        $updateFields[] = [
                            'id' => 'aec0cf66-4c70-41e1-9b61-311d4d1a8eb5',
                            'value' => $appId,
                        ];
                    }
                }

                $updatePayload = [
                    'status' => $payload['status'],
                ];

                if (!empty($updateFields)) {
                    $updatePayload['custom_fields'] = $updateFields;
                }

                $response = $this->client($apiKey)->put("/task/{$localTask->clickup_task_id}", $updatePayload);

                $this->upsertCacheFromRemoteTask($response->json(), $module->module_name, $payload);
                $results['updated']++;
                $results['details'][] = [
                    'nomor_tiket' => $payload['nomor_tiket'],
                    'aplikasi' => $payload['aplikasi'],
                    'status' => 'updated',
                ];
                continue;
            }

            if (blank($module->clickup_list_id)) {
                $this->resolveModuleListIdFromCache($module, $apiKey);

                if (blank($module->clickup_list_id)) {
                    $results['skipped']++;
                    $results['details'][] = [
                        'nomor_tiket' => $payload['nomor_tiket'],
                        'aplikasi' => $payload['aplikasi'],
                        'status' => 'skipped',
                        'message' => 'clickup_list_id belum bisa diambil otomatis dari cache module ini. Jalankan Sync Data dulu untuk backfill list ID.',
                    ];
                    continue;
                }
            }

            $taskPayload = [
                'name' => $this->buildTaskName($payload),
                'status' => $payload['status'],
            ];

            if (filled($finalBrief)) {
                $taskPayload['custom_fields'][] = [
                    'id' => 'ca78bfeb-c360-45b0-9cb4-bf6e90db5b30',
                    'value' => $finalBrief,
                ];
            }

            if (filled($payload['resolution'])) {
                $taskPayload['custom_fields'][] = [
                    'id' => 'c155dabd-5a8e-4409-8bd9-bec1c2e79ec8',
                    'value' => $payload['resolution'],
                ];
            }

            if (filled($payload['requestor_name'])) {
                $taskPayload['custom_fields'][] = [
                    'id' => 'b703d753-adc4-406e-a01b-d0b581cf66cd',
                    'value' => $payload['requestor_name'],
                ];
            }

            // Map standard mapped fields requested by user
            if (filled($payload['created_time'])) {
                $taskPayload['custom_fields'][] = [
                    'id' => '7b24c557-4735-4afc-a239-58347dd1a2e3', // Created Date Tickets
                    'value' => $payload['created_time'],
                ];
            }

            if (filled($payload['resolved_time'])) {
                $taskPayload['custom_fields'][] = [
                    'id' => 'b3f49b69-3095-4687-8b34-ea2fddd95cea', // Resolved Date Ticket
                    'value' => $payload['resolved_time'],
                ];
            }

            if (filled($payload['nomor_tiket'])) {
                $taskPayload['custom_fields'][] = [
                    'id' => 'b8c71da9-681b-4418-80e5-9dae2565e70a', // Ticket Number
                    'value' => $payload['nomor_tiket'],
                ];
            }

            if (filled($payload['aplikasi'])) {
                $appId = $this->mapAppCategory($payload['aplikasi']);
                if ($appId) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'aec0cf66-4c70-41e1-9b61-311d4d1a8eb5', // Apps
                        'value' => $appId,
                    ];
                }
            }

            if (filled($payload['email_address'])) {
                $taskPayload['custom_fields'][] = [
                    'id' => 'f1ddf2e6-ff4f-4b2d-9bac-28145776bdae', // Email Address
                    'value' => $payload['email_address'],
                ];
            }

            if (filled($payload['ticket_category'])) {
                $categoryId = $this->mapTicketCategory($payload['ticket_category']);
                if ($categoryId) {
                    $taskPayload['custom_fields'][] = [
                        'id' => 'ac661cf6-6078-4c36-b5e3-da7c74ddf7a8', // Ticket Category
                        'value' => $categoryId,
                    ];
                }
            }

            $response = $this->client($apiKey)->post("/list/{$module->clickup_list_id}/task", $taskPayload);

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

            $this->upsertCacheFromRemoteTask($response->json(), $module->module_name, $payload);
            $results['created']++;
            $results['details'][] = [
                'nomor_tiket' => $payload['nomor_tiket'],
                'aplikasi' => $payload['aplikasi'],
                'status' => 'created',
            ];
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
        $techMappings = \App\Models\TechnicianMapping::all();
        $modules = ClickUpModule::all()->keyBy('module_name');
        
        $cachedTiketIds = ClickUpTaskCache::query()
            ->whereNotNull('tiket_id')
            ->select('tiket_id', 'tipe_aplikasi')
            ->get()
            ->map(fn($t) => "{$t->tipe_aplikasi}::{$t->tiket_id}")
            ->flip()
            ->toArray();

        $previewRows = [];

        foreach ($rows as $row) {
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

            if (blank($nomorTiket)) {
                continue; // Skip completely empty rows
            }

            $payload = $this->normalizeImportRow($row, $rules, $techMappings);
            $payload['generate'] = strtoupper(trim($sourceFormat));

            $issues = [];
            // We now consolidate to 1 module, grab the first active module
            $primaryModule = $modules->where('is_active', true)->first();
            $isDuplicate = filled($payload['nomor_tiket']) && filled($payload['aplikasi']) && isset($cachedTiketIds["{$payload['aplikasi']}::{$payload['nomor_tiket']}"]);

            if (blank($payload['nomor_tiket'])) {
                $issues[] = 'Nomor tiket kosong';
            }

            if (filled($payload['aplikasi']) && !$this->mapAppCategory($payload['aplikasi'])) {
                $issues[] = 'Nama aplikasi (' . $payload['aplikasi'] . ') tidak ditemukan di daftar opsi dropdown Apps';
            }

            if (! $primaryModule) {
                $issues[] = 'Belum ada Module aktif yang terkonfigurasi di sistem (harap buat 1 module)';
            } elseif (blank($primaryModule->clickup_list_id)) {
                $issues[] = 'List ID module belum tersimpan, akan di-resolve otomatis saat submit';
            }

            if ($isDuplicate) {
                $issues[] = 'Tiket sudah ada di cache (akan di-update)';
            }

            $status = ! $primaryModule || (filled($payload['aplikasi']) && !$this->mapAppCategory($payload['aplikasi']))
                ? 'skip'
                : ($isDuplicate
                    ? 'duplicate'
                    : ($primaryModule->clickup_list_id
                        ? 'ready'
                        : 'warn'));

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

        private function client(string $apiKey): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->withoutVerifying()
            ->withHeaders([
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
            ]);
    }

    private function progressKey(string $syncToken): string
    {
        return 'clickup:sync:' . $syncToken;
    }

    private function initializeSyncProgress(string $syncToken, $modules): array
    {
        $moduleStates = [];

        foreach ($modules as $module) {
            $hasTarget = filled($module->clickup_list_id) || filled($module->clickup_view_id);
            $moduleStates[] = [
                'id' => $module->id,
                'module_name' => $module->module_name,
                'clickup_view_id' => $module->clickup_view_id,
                'clickup_list_id' => $module->clickup_list_id,
                'page' => 0,
                'pages' => 0,
                'fetched' => 0,
                'cached' => 0,
                'status' => $hasTarget ? 'queued' : 'skipped',
                'error' => $hasTarget ? null : 'clickup_list_id dan clickup_view_id kosong.',
                'done' => ! $hasTarget,
                'completed_at' => null,
            ];
        }

        $progress = [
            'sync_token' => $syncToken,
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'summary' => [
                'total_modules' => count($moduleStates),
                'completed_modules' => collect($moduleStates)->filter(fn (array $moduleState) => $moduleState['done'])->count(),
                'fetched_tasks' => 0,
                'cached_tasks' => 0,
                'progress_percent' => count($moduleStates) > 0
                    ? (int) floor(collect($moduleStates)->filter(fn (array $moduleState) => $moduleState['done'])->count() / count($moduleStates) * 100)
                    : 100,
            ],
            'modules' => $moduleStates,
        ];

        Cache::put($this->progressKey($syncToken), $progress, now()->addHours(6));

        return $progress;
    }

    private function syncProgressFromStates(
        string $syncToken,
        array $moduleStates,
        int $cachedTasks,
        int $fetchedTasks,
        string $status = 'running'
    ): array {
        $totalModules = count($moduleStates);
        $completedModules = collect($moduleStates)->filter(fn (array $moduleState) => $moduleState['done'])->count();

        $progress = [
            'sync_token' => $syncToken,
            'status' => $status,
            'started_at' => Cache::get($this->progressKey($syncToken), [])['started_at'] ?? now()->toIso8601String(),
            'finished_at' => $status === 'done' ? now()->toIso8601String() : null,
            'summary' => [
                'total_modules' => $totalModules,
                'completed_modules' => $completedModules,
                'fetched_tasks' => $fetchedTasks,
                'cached_tasks' => $cachedTasks,
                'progress_percent' => $totalModules > 0
                    ? (int) floor($completedModules / $totalModules * 100)
                    : 100,
            ],
            'modules' => array_values($moduleStates),
        ];

        Cache::put($this->progressKey($syncToken), $progress, now()->addHours(6));

        return $progress;
    }

    private function upsertCacheFromRemoteTask(array $task, string $moduleName, array $extraData = []): ClickUpTaskCache
    {
        $name = data_get($task, 'name', '');
        $clickupTaskId = data_get($task, 'id');

        $localTask = ClickUpTaskCache::query()->where('clickup_task_id', $clickupTaskId)->first();

        $clickupResolution = null;
        $clickupRequestor = null;
        $clickupBrief = null;
        $clickupApps = null;

        $customFields = data_get($task, 'custom_fields', []);
        if (is_array($customFields)) {
            foreach ($customFields as $field) {
                $fieldName = strtolower(trim((string) data_get($field, 'name', '')));
                if ($fieldName === 'resolution' || $fieldName === 'resolusi') {
                    $clickupResolution = data_get($field, 'value');
                } elseif ($fieldName === 'requestor name' || $fieldName === 'nama requestor' || $fieldName === 'requestor') {
                    $clickupRequestor = data_get($field, 'value');
                } elseif ($fieldName === 'brief problem description' || $fieldName === 'deskripsi') {
                    $clickupBrief = data_get($field, 'value');
                } elseif ($fieldName === 'apps') {
                    $valIndex = data_get($field, 'value');
                    if ($valIndex !== null) {
                        $options = data_get($field, 'type_config.options', []);
                        $clickupApps = data_get($options, $valIndex . '.name');
                        if (!$clickupApps) {
                            $selected = collect($options)->firstWhere('orderindex', $valIndex);
                            $clickupApps = data_get($selected, 'name');
                        }
                    }
                }
            }
        }

        $aplikasiDetail = data_get($extraData, 'aplikasi_detail')
            ?: data_get($extraData, 'account')
            ?: data_get($extraData, 'tenant')
            ?: data_get($extraData, 'origin')
            ?: data_get($extraData, 'aplikasi_name')
            ?: data_get($extraData, 'aplikasi')
            ?: null;

        $attributes = [
            'custom_id' => data_get($task, 'custom_id'),
            'tiket_id' => $this->extractTiketId($name),
            'name' => $name,
            'tipe_aplikasi' => strtoupper($clickupApps ?: $moduleName),
            'aplikasi' => $aplikasiDetail ? trim((string) $aplikasiDetail) : strtoupper($clickupApps ?: $moduleName),
            'status' => data_get($task, 'status.status', data_get($task, 'status', 'Open')),
        ];

        $clickupDesc = $clickupBrief;

        // Apply ClickUp data first (only if DB is empty to avoid overwriting imported data with old clickup data on initial fetch)
        if (filled($clickupDesc) && (!$localTask || empty($localTask->description))) {
            $attributes['description'] = is_string($clickupDesc) ? $clickupDesc : json_encode($clickupDesc);
        }
        if (filled($clickupResolution) && (!$localTask || empty($localTask->resolution))) {
            $attributes['resolution'] = is_string($clickupResolution) ? $clickupResolution : json_encode($clickupResolution);
        }
        if (filled($clickupRequestor) && (!$localTask || empty($localTask->requestor_name))) {
            $attributes['requestor_name'] = is_string($clickupRequestor) ? $clickupRequestor : json_encode($clickupRequestor);
        }

        // Apply Extra Data (from Excel) if available - unconditionally overwrite to sync exactly with Excel
        if (filled(data_get($extraData, 'description'))) {
            $attributes['description'] = data_get($extraData, 'description');
        }
        if (filled(data_get($extraData, 'requestor_name'))) {
            $attributes['requestor_name'] = data_get($extraData, 'requestor_name');
        }
        if (filled(data_get($extraData, 'resolution'))) {
            $attributes['resolution'] = data_get($extraData, 'resolution');
        }
        if (filled(data_get($extraData, 'created_time'))) {
            $attributes['created_time'] = data_get($extraData, 'created_time');
        }
        if (filled(data_get($extraData, 'resolved_time'))) {
            $attributes['resolved_time'] = data_get($extraData, 'resolved_time');
        }
        
        // SLA & Metrics mapping from extraData
        $metricsFields = [
            'technician', 'response_date', 'due_by_time', 'overdue_status', 
            'overdue_by', 'hold_time', 'item', 'priority', 'ticket_category'
        ];
        
        foreach ($metricsFields as $mField) {
            if (filled(data_get($extraData, $mField))) {
                // If it's ticket_category from payload, we map it to category column in DB
                if ($mField === 'ticket_category') {
                    $attributes['category'] = data_get($extraData, $mField);
                } else if ($mField === 'overdue_by') {
                    // we map overdue_by to perhaps sla_violated_technician or similar, wait
                    // earlier we added overdue_status, resolved_overdue. 
                    // Let's just map exactly what was in the migration.
                    // Migrations: time_elapsed, hold_time, actual_time, response_overdue, response_date, response_due_date, sla_response_time, sla_resolved_time
                } else {
                    $attributes[$mField] = data_get($extraData, $mField);
                }
            }
        }
        
        // Map remaining SLA metrics based on the migration fields:
        $dbMetrics = [
            'technician', 'category', 'item', 'priority',
            'time_elapsed', 'hold_time', 'actual_time', 'response_overdue', 
            'response_date', 'response_due_date', 'sla_response_time', 'sla_resolved_time',
            'due_by_time', 'overdue_status', 'resolved_overdue',
            'request_type', 'request_status', 'subcategory', 'completed_time', 'resolved_due_date', 'group',
            'generate'
        ];
        foreach ($dbMetrics as $dbMetric) {
            if (filled(data_get($extraData, $dbMetric))) {
                $attributes[$dbMetric] = data_get($extraData, $dbMetric);
            }
        }
        // specifically, payload 'overdue_by' doesn't have an exact matching db column yet unless we used a different name. 
        // Let's ignore overdue_by for DB insertion if we don't have a column, or just leave it.

        return ClickUpTaskCache::query()->updateOrCreate(
            ['clickup_task_id' => $clickupTaskId],
            $attributes
        );
    }

    private function syncModuleListIdFromTask(?ClickUpModule $module, array $task): void
    {
        if (! $module) {
            return;
        }

        $listId = data_get($task, 'list.id');

        if (blank($listId)) {
            return;
        }

        if ($module->clickup_list_id === $listId) {
            return;
        }

        $module->forceFill([
            'clickup_list_id' => $listId,
        ])->save();
    }

    private function resolveModuleListIdFromCache(ClickUpModule $module, string $apiKey): void
    {
        if (filled($module->clickup_list_id)) {
            return;
        }

        $cachedTask = ClickUpTaskCache::query()
            ->where('tipe_aplikasi', $module->module_name)
            ->orderByDesc('updated_at')
            ->first();

        if (! $cachedTask) {
            return;
        }

        $response = $this->client($apiKey)->get("/task/{$cachedTask->clickup_task_id}");

        if ($response->failed()) {
            return;
        }

        $listId = data_get($response->json(), 'list.id');

        if (blank($listId)) {
            return;
        }

        $module->forceFill([
            'clickup_list_id' => $listId,
        ])->save();
    }

    protected function normalizeImportRow(array $row, $rules = [], $techMappings = []): array
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
            foreach ($rules as $rule) {
                $ruleField = Str::of((string) $rule->excel_field)->lower()->replace(['-', '_'], ' ')->squish()->toString();
                $rowVal = data_get($normalized, $ruleField);

                if (filled($rowVal)) {
                    if (strtolower(trim((string) $rowVal)) === strtolower(trim((string) $rule->excel_value))) {
                        $aplikasi = $rule->target_module;
                        // Do not break here, allow newer rules to overwrite older ones for exception cases
                    }
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
            data_get($normalized, 'created time'),
            data_get($normalized, 'created date'),
            data_get($normalized, 'created at'),
            data_get($normalized, 'waktu dibuat'),
        ])->first(fn ($value) => filled($value), '');

        $resolvedTimeRaw = collect([
            data_get($normalized, 'resolved time'),
            data_get($normalized, 'resolved date'),
            data_get($normalized, 'solved time'),
            data_get($normalized, 'solved date'),
            data_get($normalized, 'waktu selesai'),
        ])->first(fn ($value) => filled($value), '');

        $createdTime = $this->formatDateString($createdTimeRaw);
        $resolvedTime = $this->formatDateString($resolvedTimeRaw);

        $emailAddress = collect([
            data_get($normalized, 'email address'),
            data_get($normalized, 'email'),
            data_get($normalized, 'alamat email'),
        ])->first(fn ($value) => filled($value), '');

        $technician = collect([
            data_get($normalized, 'inisial time'),
            data_get($normalized, 'initial time'),
            data_get($normalized, 'inisial'),
            data_get($normalized, 'initial'),
            data_get($normalized, 'inisial teknisi'),
            data_get($normalized, 'technician initial'),
            data_get($normalized, 'technician'),
            data_get($normalized, 'nama teknisi'),
            data_get($normalized, 'created_by'),
            data_get($normalized, 'created by')
        ])->first(fn ($value) => filled($value), '');

        if (filled($techMappings) && filled($technician)) {
            $mapping = collect($techMappings)->first(fn($m) => strtolower($m->original_name) === strtolower($technician));
            if ($mapping) {
                $technician = $mapping->mapped_name;
            }
        }

        $responseDate = collect([
            data_get($normalized, 'response date'),
            data_get($normalized, 'responded date')
        ])->first(fn ($value) => filled($value), '');

        $dueByTime = collect([
            data_get($normalized, 'due by time'),
            data_get($normalized, 'dueby time'),
            data_get($normalized, 'resolved due date'),
            data_get($normalized, 'tanggal jatuh tempo')
        ])->first(fn ($value) => filled($value), '');

        $overdueStatus = collect([
            data_get($normalized, 'overdue status'),
            data_get($normalized, 'resolved overdue'),
            data_get($normalized, 'status overdue')
        ])->first(fn ($value) => filled($value), '');

        $overdueBy = collect([
            data_get($normalized, 'overdue by'),
            data_get($normalized, 'sla violated technician'),
            data_get($normalized, 'fr sla violated technician')
        ])->first(fn ($value) => filled($value), '');

        $holdTime = collect([
            data_get($normalized, 'hold time'),
            data_get($normalized, 'onhold time')
        ])->first(fn ($value) => filled($value), '');

        $item = collect([
            data_get($normalized, 'item'),
            data_get($normalized, 'service category')
        ])->first(fn ($value) => filled($value), '');

        $rawPriority = collect([
            data_get($normalized, 'priority'),
            data_get($normalized, 'prioritas')
        ])->first(fn ($value) => filled($value), '');
        
        $priority = $this->normalizePriority($rawPriority);

        $category = collect([
            data_get($normalized, 'request type'),
            data_get($normalized, 'category')
        ])->first(fn ($value) => filled($value), '');

        // Extracting all requested fields for DB metrics:
        $requestType = data_get($normalized, 'request type', '');
        $requestStatus = data_get($normalized, 'request status', '');
        
        $subcategory = collect([
            data_get($normalized, 'subcategory'),
            data_get($normalized, 'subkategori'),
            data_get($normalized, 'account')
        ])->first(fn ($value) => filled($value), '');
        
        $completedTime = data_get($normalized, 'completed time', '');
        
        // Match with the ones we just combined above
        $resolvedOverdue = $overdueStatus;
        $resolvedDueDate = $dueByTime;
        
        $group = collect([data_get($normalized, 'group'), data_get($normalized, 'grup')])->first(fn ($v) => filled($v), '');
        
        $timeElapsed = collect([
            data_get($normalized, 'time elapsed'),
            data_get($normalized, 'elapsed time')
        ])->first(fn ($value) => filled($value), '');
        
        $actualTime = collect([
            data_get($normalized, 'actual time'),
            data_get($normalized, 'time elapsed') // Fallback to SDP's 'Time Elapsed'
        ])->first(fn ($value) => filled($value), '');
        $responseOverdue = collect([
            data_get($normalized, 'first response overdue status'),
            data_get($normalized, 'response overdue')
        ])->first(fn ($value) => filled($value), '');
        
        $responseDueDate = collect([
            data_get($normalized, 'response dueby time'),
            data_get($normalized, 'response due date')
        ])->first(fn ($value) => filled($value), '');
        
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

    private function buildTaskName(array $payload): string
    {
        $nomorTiket = $payload['nomor_tiket'];

        // Only add '#' prefix if nomor_tiket is purely numeric (e.g. "60902")
        // LMD-style numbers like "LMD/2026/1/6350" are used as-is
        $prefix = ctype_digit($nomorTiket) ? '#' : '';
        $taskName = $prefix . $nomorTiket;

        if ($payload['subject'] !== '') {
            $taskName .= ' ' . $payload['subject'];
        }

        return $taskName;
    }

    private function normalizePriority(string $priority): string
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

    private function mapTicketCategory(string $category): ?string
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

        $lower = strtolower(trim($category));
        
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        return null;
    }

    private function mapAppCategory(string $appName): ?string
    {
        $map = [
            'cafeins' => 'bbe04f86-d669-4216-9d74-50b06d57c920',
            'sales mastes' => '66596674-9673-4b1a-be26-6192038774dc',
            'cmms' => 'ed3788b1-277c-4c32-b130-9379356ee3e0',
            'myla' => 'f80b800d-54aa-4389-b2fb-cdf4c623f72a',
            'psa pca' => 'd053f47c-d816-4caf-b91c-88372f9d3b27',
            'pmois' => 'cfb962e5-71f3-4609-9b56-c8b784ccb325',
            'doc tracking' => '655932d5-1747-442e-9619-e74f44592cc2',
            'starla' => 'b015af83-26e5-48eb-bedc-d326c9145ab8',
            'ebesha' => '730a53d7-3658-4fd6-aa4e-89fa91bf3a1b',
            'ultima & starlink' => 'acc57591-7221-4118-8e03-d366d9a76be4',
            'gntu' => '099e4653-4973-4da1-8cb4-ec709af6f812',
            'jarin' => 'd225ea0a-7258-4d94-b952-6dc73b33dc01',
        ];

        $lower = strtolower(trim($appName));
        return $map[$lower] ?? null;
    }

    private function modulePayload(ClickUpModule $module): array
    {
        return [
            'id' => $module->id,
            'module_name' => $module->module_name,
            'clickup_view_id' => $module->clickup_view_id,
            'clickup_list_id' => $module->clickup_list_id,
            'is_active' => $module->is_active,
            'last_synced_at' => $module->last_synced_at?->toIso8601String(),
            'tasks_count' => ClickUpTaskCache::query()
                ->where('tipe_aplikasi', $module->module_name)
                ->count(),
        ];
    }

    private function taskPayload(ClickUpTaskCache $task): array
    {
        return [
            'id' => $task->id,
            'clickup_task_id' => $task->clickup_task_id,
            'custom_id' => $task->custom_id,
            'tiket_id' => $task->tiket_id,
            'name' => $task->name,
            'tipe_aplikasi' => $task->tipe_aplikasi,
            'aplikasi' => $task->aplikasi,
            'status' => $task->status,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Extract the ticket number from a ClickUp task name.
     *
     * Supports patterns like:
     *  - "#708074 CAFEINS - Nojar tidak muncul" => "708074"
     *  - "#709269 Cafeins - request reset password" => "709269"
     *  - "LMD/2026/1/6350 Some subject" => "LMD/2026/1/6350"
     */
    private function extractTiketId(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // Pattern 1: starts with # followed by digits (e.g. "#708074 ...")
        if (preg_match('/^#(\d+)/', $name, $matches)) {
            return $matches[1];
        }

        // Pattern 2: starts with a non-numeric ticket format like "LMD/2026/1/6350"
        // Take everything before the first space as the ticket ID
        $firstToken = Str::before($name, ' ');

        if (filled($firstToken) && $firstToken !== $name) {
            return rtrim($firstToken, ',-:;');
        }

        return null;
    }

    private function formatDateString(?string $dateString): string
    {
        if (blank($dateString) || $dateString === '-') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse(trim($dateString))->format('M d, Y h:i A');
        } catch (\Throwable $e) {
            return trim($dateString);
        }
    }
}