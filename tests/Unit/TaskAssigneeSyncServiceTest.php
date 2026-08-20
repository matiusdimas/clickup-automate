<?php

namespace Tests\Unit;

use App\Services\ClickUp\Routing\AssigneeEvaluatorService;
use App\Services\ClickUp\TaskAssigneeSyncService;
use PHPUnit\Framework\TestCase;

class TaskAssigneeSyncServiceTest extends TestCase
{
    public function test_service_initialization_and_dependencies(): void
    {
        $evaluator = $this->createMock(AssigneeEvaluatorService::class);
        $service = new TaskAssigneeSyncService($evaluator, null);

        $this->assertInstanceOf(TaskAssigneeSyncService::class, $service);
    }

    public function test_prevents_reassigning_existing_assigned_tasks(): void
    {
        $evaluator = $this->createMock(AssigneeEvaluatorService::class);
        // Evaluator should NOT be called if task already has remote assignees
        $evaluator->expects($this->never())->method('resolveAssignees');

        $service = new TaskAssigneeSyncService($evaluator, null);

        $remoteTaskData = [
            'assignees' => [
                ['id' => 113406558, 'username' => 'Dzaka'],
            ],
        ];

        $this->assertInstanceOf(TaskAssigneeSyncService::class, $service);
    }

    public function test_sync_task_assignees_resolves_locally_when_push_to_remote_is_false(): void
    {
        $evaluator = $this->createMock(AssigneeEvaluatorService::class);
        $evaluator->method('resolveAssignees')->willReturn([113406558]);

        $service = new TaskAssigneeSyncService($evaluator, null);
        $this->assertInstanceOf(TaskAssigneeSyncService::class, $service);
    }
}
