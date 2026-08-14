<?php

namespace Tests\Unit;

use App\Services\ClickUp\Routing\AssigneeEvaluatorService;
use App\Services\ClickUp\ClickUpUserRegistry;
use PHPUnit\Framework\TestCase;

class AssigneeEvaluatorServiceTest extends TestCase
{
    private AssigneeEvaluatorService $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new AssigneeEvaluatorService();
    }

    public function test_default_main_app_assignees(): void
    {
        $assignees = $this->evaluator->resolveAssignees('Cafeins');

        $this->assertCount(2, $assignees);
        $this->assertContains(113406558, $assignees); // Dzaka
        $this->assertContains(95553944, $assignees);  // Support LMD
    }

    public function test_default_infra_app_assignees(): void
    {
        $assignees = $this->evaluator->resolveAssignees('Infra - cPanel Arint');

        $this->assertCount(2, $assignees);
        $this->assertContains(95657721, $assignees); // Mukhlis
        $this->assertContains(95553944, $assignees);  // Support LMD
    }

    public function test_custom_assignee_rule_override(): void
    {
        $customRules = [
            [
                'app_category' => 'SPECIFIC',
                'target_app' => 'Cafeins',
                'assignee_ids' => [95657720, 95553944], // Ilyas + Support
            ],
        ];

        $assignees = $this->evaluator->resolveAssignees('Cafeins', [], $customRules);

        $this->assertCount(2, $assignees);
        $this->assertContains(95657720, $assignees); // Ilyas
        $this->assertContains(95553944, $assignees); // Support LMD
    }

    public function test_user_registry_returns_all_members(): void
    {
        $users = ClickUpUserRegistry::getAll();

        $this->assertGreaterThanOrEqual(6, count($users));
        $dzaka = ClickUpUserRegistry::find(113406558);
        $this->assertNotNull($dzaka);
        $this->assertEquals('dzaka@lmd.co.id', $dzaka['email']);
    }
}
