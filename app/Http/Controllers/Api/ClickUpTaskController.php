<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetTasksRequest;
use App\Models\ClickUpTaskCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClickUpTaskController extends Controller
{
    public function index(GetTasksRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = ClickUpTaskCache::query()
            ->when($validated['module'] ?? null, fn ($builder, $module) => $builder->where('tipe_aplikasi', strtoupper(trim($module))))
            ->when($validated['aplikasi'] ?? null, fn ($builder, $aplikasi) => $builder->where('aplikasi', trim($aplikasi)))
            ->when($validated['technician'] ?? null, fn ($builder, $tech) => $builder->where('technician', trim($tech)))
            ->when($validated['status'] ?? null, fn ($builder, $st) => $builder->where('status', strtolower(trim($st))))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->where(function ($q) use ($search) {
                $term = '%' . trim($search) . '%';
                $q->where('name', 'like', $term)
                  ->orWhere('tiket_id', 'like', $term)
                  ->orWhere('custom_id', 'like', $term);
            }))
            ->orderByDesc('updated_at');

        $paginator = $query->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $task = ClickUpTaskCache::query()
            ->where('id', $id)
            ->orWhere('clickup_task_id', $id)
            ->orWhere('tiket_id', $id)
            ->orWhere('custom_id', $id)
            ->first();

        if (! $task) {
            return response()->json([
                'success' => false,
                'message' => 'Tiket/Task tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $task,
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $query = ClickUpTaskCache::query()
            ->when($validated['module'] ?? null, fn ($builder, $module) => $builder->where('tipe_aplikasi', strtoupper(trim($module))))
            ->when($validated['search'] ?? null, fn ($builder, $search) => $builder->where('name', 'like', '%' . trim($search) . '%'))
            ->orderByDesc('updated_at');

        $tasks = $query->get()->map(fn (ClickUpTaskCache $task) => [
            'id' => $task->id,
            'clickup_task_id' => $task->clickup_task_id,
            'custom_id' => $task->custom_id,
            'tiket_id' => $task->tiket_id,
            'name' => $task->name,
            'tipe_aplikasi' => $task->tipe_aplikasi,
            'aplikasi' => $task->aplikasi,
            'status' => $task->status,
            'updated_at' => $task->updated_at?->toIso8601String(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $tasks,
        ]);
    }
}
