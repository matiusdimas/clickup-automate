<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssigneeRuleRequest;
use App\Models\ClickUpAssigneeRule;
use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\ClickUpApiClient;
use App\Services\ClickUp\ClickUpUserRegistry;
use App\Services\ClickUp\TaskAssigneeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClickUpAssigneeRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = ClickUpAssigneeRule::query()
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    public function assigneesList(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ClickUpUserRegistry::getAll(),
        ]);
    }

    public function store(StoreAssigneeRuleRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['assignee_names'])) {
            $names = [];
            foreach ($data['assignee_ids'] as $id) {
                $user = ClickUpUserRegistry::find($id);
                $names[] = $user ? $user['name'] : "User #{$id}";
            }
            $data['assignee_names'] = $names;
        }

        $rule = ClickUpAssigneeRule::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Aturan penugasan assignee berhasil disimpan.',
            'data' => $rule,
        ], 201);
    }

    public function update(StoreAssigneeRuleRequest $request, int $id): JsonResponse
    {
        $rule = ClickUpAssigneeRule::findOrFail($id);
        $data = $request->validated();

        if (empty($data['assignee_names'])) {
            $names = [];
            foreach ($data['assignee_ids'] as $userId) {
                $user = ClickUpUserRegistry::find($userId);
                $names[] = $user ? $user['name'] : "User #{$userId}";
            }
            $data['assignee_names'] = $names;
        }

        $rule->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Aturan penugasan assignee berhasil diperbarui.',
            'data' => $rule,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $rule = ClickUpAssigneeRule::findOrFail($id);
        $rule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Aturan penugasan assignee berhasil dihapus.',
        ]);
    }

    /**
     * Batch push missing assignees to ClickUp REST API for cached tasks.
     */
    public function syncAssignees(Request $request, ClickUpApiClient $apiClient): JsonResponse
    {
        $ticketId = $request->input('tiket_id');
        $query = ClickUpTaskCache::query();

        if (filled($ticketId)) {
            $query->where('tiket_id', 'LIKE', "%{$ticketId}%")
                ->orWhere('clickup_task_id', $ticketId);
        }

        $tasks = $query->get();
        $syncService = new TaskAssigneeSyncService(null, $apiClient);
        $updatedCount = 0;

        foreach ($tasks as $taskCache) {
            $assignees = $syncService->pushAssigneesToClickUp($taskCache, true);
            if (!empty($assignees)) {
                $updatedCount++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Proses sinkronisasi assignees selesai. Total {$updatedCount} task berhasil di-update ke ClickUp API.",
            'data' => [
                'total_tasks' => $tasks->count(),
                'updated_tasks' => $updatedCount,
            ],
        ]);
    }
}
