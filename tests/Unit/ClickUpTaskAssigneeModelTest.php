<?php

namespace Tests\Unit;

use App\Models\ClickUpTaskAssignee;
use App\Models\ClickUpTaskCache;
use PHPUnit\Framework\TestCase;

class ClickUpTaskAssigneeModelTest extends TestCase
{
    public function test_model_fillable_and_casts(): void
    {
        $assignee = new ClickUpTaskAssignee([
            'clickup_task_cache_id' => 1,
            'clickup_task_id' => 'task123',
            'tiket_id' => '#1001',
            'clickup_user_id' => 113406558,
            'user_name' => 'Muhammad Dzaka Murran',
            'user_email' => 'dzaka@lmd.co.id',
        ]);

        $this->assertEquals(1, $assignee->clickup_task_cache_id);
        $this->assertEquals('task123', $assignee->clickup_task_id);
        $this->assertEquals(113406558, $assignee->clickup_user_id);
        $this->assertEquals('Muhammad Dzaka Murran', $assignee->user_name);
        $this->assertEquals('dzaka@lmd.co.id', $assignee->user_email);
    }

    public function test_relationship_definition(): void
    {
        $taskCache = new ClickUpTaskCache();
        $this->assertTrue(method_exists($taskCache, 'assignees'));

        $taskAssignee = new ClickUpTaskAssignee();
        $this->assertTrue(method_exists($taskAssignee, 'taskCache'));
    }
}
