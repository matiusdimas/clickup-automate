<?php

namespace App\Services\ClickUp;

use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ClickUpSyncService
{
    private const SYNC_LOCK_KEY = 'clickup:sync_active_lock';

    public function __construct(
        private readonly ClickUpApiClient $apiClient,
        private readonly ImportNormalizerService $normalizer
    ) {
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

    public function startSync(?string $syncToken = null): array
    {
        if (Cache::has(self::SYNC_LOCK_KEY)) {
            $existingToken = Cache::get(self::SYNC_LOCK_KEY);
            $progress = Cache::get($this->progressKey($existingToken));

            // Auto-clear stale or completed lock
            if (!$progress || in_array($progress['status'] ?? '', ['done', 'failed', 'missing', 'not_found', 'completed']) || ($syncToken && $existingToken === $syncToken)) {
                Cache::forget(self::SYNC_LOCK_KEY);
            } else {
                return [
                    'status' => 'already_running',
                    'sync_token' => $existingToken,
                    'message' => 'Proses sinkronisasi sedang berjalan pada tab atau perangkat lain.',
                ];
            }
        }

        $syncToken = $syncToken ?: (string) Str::uuid();
        
        Cache::put(self::SYNC_LOCK_KEY, $syncToken, now()->addHours(6));

        // Initialize progress immediately so frontend doesn't get 'missing' while background starts
        $modules = ClickUpModule::query()->where('is_active', true)->get();
        $this->initializeSyncProgress($syncToken, $modules);

        return [
            'status' => 'started',
            'sync_token' => $syncToken,
            'message' => 'Proses sinkronisasi telah dimulai di latar belakang.',
        ];
    }

    public function runSync(string $syncToken): void
    {
        set_time_limit(0);

        try {
            $modules = ClickUpModule::query()->where('is_active', true)->get();

            // Progress should be initialized by startSync, we just need to get it or recreate if lost
            $progress = Cache::get($this->progressKey($syncToken));
            if (!$progress) {
                $progress = $this->initializeSyncProgress($syncToken, $modules);
            }
            
            $moduleStates = collect($progress['modules'])->keyBy('module_name')->all();
        $cachedTasks = 0;
        $fetchedTasks = 0;

            while (true) {
                // Ensure lock hasn't been maliciously cleared or expired
                if (!Cache::has(self::SYNC_LOCK_KEY) || Cache::get(self::SYNC_LOCK_KEY) !== $syncToken) {
                    Log::warning("Sync lock lost or hijacked for token: {$syncToken}. Aborting loop.");
                    break;
                }

                $activeModules = collect($moduleStates)
                    ->filter(fn (array $moduleState) => ! $moduleState['done'] && (filled($moduleState['clickup_list_id']) || filled($moduleState['clickup_view_id'])))
                    ->all();

                if (empty($activeModules)) {
                    break;
                }

                $moduleOrder = array_keys($activeModules);

                $apiKey = $this->apiClient->getApiKey();
            $baseUrl = $this->apiClient->getBaseUrl();

            $responses = $this->apiClient->client()->pool(function (Pool $pool) use ($activeModules, $apiKey, $baseUrl) {
                $requests = [];

                foreach ($activeModules as $moduleName => $moduleState) {
                    $endpoint = filled($moduleState['clickup_list_id'] ?? null)
                        ? $baseUrl . "/list/{$moduleState['clickup_list_id']}/task"
                        : $baseUrl . "/view/{$moduleState['clickup_view_id']}/task";

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
                    if ($response->status() === 429) {
                        $retryAfter = $response->header('Retry-After');
                        $sleepSec = ($retryAfter && is_numeric($retryAfter)) ? (int) $retryAfter : 3;
                        sleep(min(10, max(1, $sleepSec)));
                        continue;
                    }

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

                usleep(200000);
            }

            $this->syncProgressFromStates($syncToken, $moduleStates, $cachedTasks, $fetchedTasks, 'done');

        } catch (\Throwable $e) {
            Log::error("ClickUp Sync Error: " . $e->getMessage());
        } finally {
            // Free the global lock if we were the ones holding it
            if (Cache::get(self::SYNC_LOCK_KEY) === $syncToken) {
                Cache::forget(self::SYNC_LOCK_KEY);
            }
        }
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

    public function upsertCacheFromRemoteTask(?array $task, string $moduleName, array $extraData = []): ?ClickUpTaskCache
    {
        if (empty($task)) {
            return null;
        }

        $name = data_get($task, 'name', data_get($extraData, 'name', ''));
        $clickupTaskId = data_get($task, 'id');

        $tiketId = data_get($extraData, 'nomor_tiket') ?: $this->extractTiketId($name);
        $cleanTiket = $this->normalizer->cleanTiketId($tiketId);

        $localTask = null;
        if (filled($clickupTaskId)) {
            $localTask = ClickUpTaskCache::query()->where('clickup_task_id', $clickupTaskId)->first();
        }

        if (! $localTask && filled($cleanTiket)) {
            $localTask = ClickUpTaskCache::query()
                ->where(function ($q) use ($tiketId, $cleanTiket) {
                    $q->where('tiket_id', $tiketId)
                      ->orWhere('tiket_id', $cleanTiket)
                      ->orWhere('tiket_id', '#' . $cleanTiket);
                })
                ->first();

            if ($localTask && blank($clickupTaskId)) {
                $clickupTaskId = $localTask->clickup_task_id;
            }
        }

        if (blank($clickupTaskId)) {
            return null;
        }

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

        if (filled($clickupDesc) && (!$localTask || empty($localTask->description))) {
            $attributes['description'] = is_string($clickupDesc) ? $clickupDesc : json_encode($clickupDesc);
        }
        if (filled($clickupResolution) && (!$localTask || empty($localTask->resolution))) {
            $attributes['resolution'] = is_string($clickupResolution) ? $clickupResolution : json_encode($clickupResolution);
        }
        if (filled($clickupRequestor) && (!$localTask || empty($localTask->requestor_name))) {
            $attributes['requestor_name'] = is_string($clickupRequestor) ? $clickupRequestor : json_encode($clickupRequestor);
        }

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

        $metricsFields = [
            'technician', 'response_date', 'due_by_time', 'overdue_status', 
            'overdue_by', 'hold_time', 'item', 'priority', 'ticket_category'
        ];

        foreach ($metricsFields as $mField) {
            if (filled(data_get($extraData, $mField))) {
                if ($mField === 'ticket_category') {
                    $attributes['category'] = data_get($extraData, $mField);
                } else {
                    $attributes[$mField] = data_get($extraData, $mField);
                }
            }
        }

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

    public function resolveModuleListIdFromCache(ClickUpModule $module): void
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

        $response = $this->apiClient->client()->get("/task/{$cachedTask->clickup_task_id}");

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

    private function extractTiketId(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if (preg_match('/^#(\d+)/', $name, $matches)) {
            return $matches[1];
        }

        $firstToken = Str::before($name, ' ');

        if (filled($firstToken) && $firstToken !== $name) {
            return rtrim($firstToken, ',-:;');
        }

        return null;
    }
}
