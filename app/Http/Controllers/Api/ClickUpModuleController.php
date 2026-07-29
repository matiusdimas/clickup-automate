<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClickUpModuleRequest;
use App\Http\Requests\UpdateClickUpModuleRequest;
use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ClickUpModuleController extends Controller
{
    public function overview(): JsonResponse
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

        return response()->json([
            'success' => true,
            'summary' => [
                'module_count' => $modules->count(),
                'active_module_count' => $modules->where('is_active', true)->count(),
                'task_count' => ClickUpTaskCache::count(),
                'last_synced_at' => $lastSyncedAt ? Carbon::parse($lastSyncedAt)->toIso8601String() : null,
            ],
            'active_sync_token' => Cache::get('clickup:sync_active_lock'),
            'active_import_token' => Cache::get('clickup:import_active_lock'),
            'modules' => $modules,
            'recent_tasks' => $recentTasks,
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ClickUpModule::query()
                ->orderBy('module_name')
                ->get()
                ->map(fn (ClickUpModule $module) => [
                    'id' => $module->id,
                    'module_name' => $module->module_name,
                    'clickup_view_id' => $module->clickup_view_id,
                    'clickup_list_id' => $module->clickup_list_id,
                    'is_active' => $module->is_active,
                    'last_synced_at' => $module->last_synced_at?->toIso8601String(),
                    'created_at' => $module->created_at?->toIso8601String(),
                    'updated_at' => $module->updated_at?->toIso8601String(),
                ]),
        ]);
    }

    public function store(StoreClickUpModuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $module = ClickUpModule::create([
            'module_name' => $validated['module_name'],
            'clickup_view_id' => $validated['clickup_view_id'],
            'clickup_list_id' => filled($validated['clickup_list_id'] ?? null) ? $validated['clickup_list_id'] : null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Module berhasil disimpan.',
            'data' => [
                'id' => $module->id,
                'module_name' => $module->module_name,
                'clickup_view_id' => $module->clickup_view_id,
                'clickup_list_id' => $module->clickup_list_id,
                'is_active' => $module->is_active,
                'last_synced_at' => $module->last_synced_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function update(UpdateClickUpModuleRequest $request, ClickUpModule $module): JsonResponse
    {
        $validated = $request->validated();

        $module->update([
            'module_name' => $validated['module_name'],
            'clickup_view_id' => $validated['clickup_view_id'],
            'clickup_list_id' => filled($validated['clickup_list_id'] ?? null) ? $validated['clickup_list_id'] : null,
            'is_active' => $validated['is_active'] ?? $module->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Module berhasil diperbarui.',
            'data' => [
                'id' => $module->id,
                'module_name' => $module->module_name,
                'clickup_view_id' => $module->clickup_view_id,
                'clickup_list_id' => $module->clickup_list_id,
                'is_active' => $module->is_active,
                'last_synced_at' => $module->last_synced_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(ClickUpModule $module): JsonResponse
    {
        $module->delete();

        return response()->json([
            'success' => true,
            'message' => 'Module berhasil dihapus.',
        ]);
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
            'description' => $task->description,
            'requestor_name' => $task->requestor_name,
            'resolution' => $task->resolution,
            'technician' => $task->technician,
            'created_time' => $task->created_time,
            'resolved_time' => $task->resolved_time,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }
}
