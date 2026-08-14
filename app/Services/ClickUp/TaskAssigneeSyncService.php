<?php

namespace App\Services\ClickUp;

use App\Models\ClickUpTaskAssignee;
use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\Routing\AssigneeEvaluatorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TaskAssigneeSyncService
{
    private AssigneeEvaluatorService $assigneeEvaluator;
    private ?ClickUpApiClient $apiClient;

    public function __construct(?AssigneeEvaluatorService $assigneeEvaluator = null, ?ClickUpApiClient $apiClient = null)
    {
        $this->assigneeEvaluator = $assigneeEvaluator ?? new AssigneeEvaluatorService();
        $this->apiClient = $apiClient;
    }

    /**
     * Synchronize task assignees.
     * If remote task has assignees, persist them locally.
     * If local DB already has assignees for this task, PRESERVE them (do NOT overwrite).
     * If task assignees are completely empty, resolve assignees and push to ClickUp API once.
     *
     * @param ClickUpTaskCache $taskCache
     * @param array<string, mixed> $remoteTaskData
     * @return array<int, int>
     */
    public function syncTaskAssignees(ClickUpTaskCache $taskCache, array $remoteTaskData = []): array
    {
        $remoteAssignees = $remoteTaskData['assignees'] ?? [];

        // 1. If remote assignees exist in task payload, persist them locally
        if (!empty($remoteAssignees)) {
            return $this->persistAssigneesFromRemote($taskCache, $remoteAssignees);
        }

        // 2. Check if local DB already has assignees for this task
        $existingLocalAssignees = ClickUpTaskAssignee::query()
            ->where('clickup_task_cache_id', $taskCache->id)
            ->get();

        if ($existingLocalAssignees->isNotEmpty()) {
            // Task already assigned! DO NOT overwrite or re-evaluate rules!
            return $existingLocalAssignees->pluck('clickup_user_id')->toArray();
        }

        // 3. Task assignees are empty everywhere -> Resolve & Push to ClickUp API 1x
        return $this->pushAssigneesToClickUp($taskCache, false);
    }

    /**
     * Resolve assignees for a task and push them via HTTP PUT to ClickUp API.
     * If $force is false and local assignees already exist, existing assignees are preserved without re-evaluating rules.
     *
     * @param ClickUpTaskCache $taskCache
     * @param bool $force
     * @return array<int, int>
     */
    public function pushAssigneesToClickUp(ClickUpTaskCache $taskCache, bool $force = false): array
    {
        // If not forcing, preserve existing assignees
        if (!$force) {
            $existingUserIds = ClickUpTaskAssignee::query()
                ->where('clickup_task_cache_id', $taskCache->id)
                ->pluck('clickup_user_id')
                ->toArray();

            if (!empty($existingUserIds)) {
                return $existingUserIds;
            }
        }

        $appName = $taskCache->aplikasi ?? '';
        $resolvedAssigneeIds = $this->assigneeEvaluator->resolveAssignees($appName);

        if (empty($resolvedAssigneeIds)) {
            return [];
        }

        // Push to ClickUp API via HTTP PUT /task/{task_id}
        if (!empty($taskCache->clickup_task_id)) {
            try {
                $client = $this->apiClient ?? new ClickUpApiClient();
                $response = $client->requestWithRetry(
                    fn () => $client->client()->put("/task/{$taskCache->clickup_task_id}", [
                        'assignees' => [
                            'add' => $resolvedAssigneeIds,
                        ],
                    ])
                );

                if ($response->successful()) {
                    Log::info("Successfully pushed assignees " . json_encode($resolvedAssigneeIds) . " to ClickUp API for task {$taskCache->clickup_task_id} ({$taskCache->tiket_id})");
                } else {
                    Log::warning("ClickUp API returned {$response->status()} updating assignees for task {$taskCache->clickup_task_id}: " . $response->body());
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to push assignees to ClickUp API for task {$taskCache->clickup_task_id}: " . $e->getMessage());
            }
        }

        // Persist resolved assignees in local MySQL table
        return $this->persistResolvedAssignees($taskCache, $resolvedAssigneeIds);
    }

    /**
     * Persist remote assignee objects into clickup_task_assignees table.
     */
    private function persistAssigneesFromRemote(ClickUpTaskCache $taskCache, array $remoteAssignees): array
    {
        $storedUserIds = [];

        foreach ($remoteAssignees as $member) {
            $userId = (int) ($member['id'] ?? $member['user']['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $userName = $member['username'] ?? $member['user']['username'] ?? $member['name'] ?? null;
            $userEmail = $member['email'] ?? $member['user']['email'] ?? null;

            ClickUpTaskAssignee::updateOrCreate(
                [
                    'clickup_task_cache_id' => $taskCache->id,
                    'clickup_user_id' => $userId,
                ],
                [
                    'clickup_task_id' => $taskCache->clickup_task_id,
                    'tiket_id' => $taskCache->tiket_id,
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'assigned_at' => Carbon::now(),
                ]
            );

            $storedUserIds[] = $userId;
        }

        if (!empty($storedUserIds)) {
            ClickUpTaskAssignee::query()
                ->where('clickup_task_cache_id', $taskCache->id)
                ->whereNotIn('clickup_user_id', $storedUserIds)
                ->delete();
        }

        return $storedUserIds;
    }

    /**
     * Persist resolved assignee IDs locally.
     */
    private function persistResolvedAssignees(ClickUpTaskCache $taskCache, array $assigneeIds): array
    {
        $storedUserIds = [];

        foreach ($assigneeIds as $userId) {
            $user = ClickUpUserRegistry::find($userId);
            $userName = $user['name'] ?? "User #{$userId}";
            $userEmail = $user['email'] ?? null;

            ClickUpTaskAssignee::updateOrCreate(
                [
                    'clickup_task_cache_id' => $taskCache->id,
                    'clickup_user_id' => $userId,
                ],
                [
                    'clickup_task_id' => $taskCache->clickup_task_id,
                    'tiket_id' => $taskCache->tiket_id,
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'assigned_at' => Carbon::now(),
                ]
            );

            $storedUserIds[] = (int) $userId;
        }

        return $storedUserIds;
    }
}
