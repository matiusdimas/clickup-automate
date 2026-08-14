<?php

namespace Tests\Unit;

use App\Models\ClickUpAssigneeRule;
use PHPUnit\Framework\TestCase;

class ClickUpAssigneeRuleApiTest extends TestCase
{
    public function test_model_casts_and_attributes(): void
    {
        $rule = new ClickUpAssigneeRule([
            'rule_name' => 'Main Apps Rule',
            'app_category' => 'MAIN',
            'assignee_ids' => [113406558, 95553944],
            'assignee_names' => ['Muhammad Dzaka Murran', 'Support LMD'],
            'is_active' => true,
        ]);

        $this->assertEquals('MAIN', $rule->app_category);
        $this->assertIsArray($rule->assignee_ids);
        $this->assertCount(2, $rule->assignee_ids);
        $this->assertContains(113406558, $rule->assignee_ids);
        $this->assertTrue($rule->is_active);
    }

    public function test_model_updates_assignee_ids_and_category(): void
    {
        $rule = new ClickUpAssigneeRule([
            'app_category' => 'MAIN',
            'assignee_ids' => [113406558],
        ]);

        $rule->fill([
            'app_category' => 'SPECIFIC',
            'target_app' => 'Cafeins',
            'assignee_ids' => [113406558, 95657720], // Dzaka + Ilyas
        ]);

        $this->assertEquals('SPECIFIC', $rule->app_category);
        $this->assertEquals('Cafeins', $rule->target_app);
        $this->assertCount(2, $rule->assignee_ids);
        $this->assertContains(95657720, $rule->assignee_ids);
    }
}
