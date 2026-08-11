<?php

namespace App\Http\Controllers\Api;

use App\DTOs\DashboardFilterDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\GetTasksRequest;
use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\DashboardFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClickUpTaskController extends Controller
{
    public function index(GetTasksRequest $request, DashboardFilterService $filterService): JsonResponse
    {
        $dto = DashboardFilterDTO::fromRequest($request);
        $query = ClickUpTaskCache::query();
        $filterService->applyFilters($query, $dto);

        if ($search = $request->query('search')) {
            $term = '%' . trim((string) $search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('tiket_id', 'like', $term)
                  ->orWhere('custom_id', 'like', $term);
            });
        }

        $paginator = $query->orderByDesc('updated_at')->paginate($dto->perPage);

        return response()->json([
            'success' => true,
            'available_filters' => $filterService->getAvailableFilters(),
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

        return response()->json([
            'success' => true,
            'total' => $query->count(),
            'data' => $query->get(),
        ]);
    }
}
