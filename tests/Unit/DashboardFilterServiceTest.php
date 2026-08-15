<?php

namespace Tests\Unit;

use App\DTOs\DashboardFilterDTO;
use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\DashboardFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardFilterService $filterService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filterService = new DashboardFilterService();
        $this->filterService->clearAvailableFiltersCache();
    }

    public function test_custom_date_range_filters_beyond_90_days_without_limitation(): void
    {
        // 1. Task in Jan 2026 (180 days ago from July)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-jan',
            'name' => 'January Task',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Open',
            'created_time' => 'Jan 10, 2026 10:00 AM',
        ]);

        // 2. Task in July 2026
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-july',
            'name' => 'July Task',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Closed',
            'created_time' => 'Jul 15, 2026 02:00 PM',
        ]);

        // 3. Task in Dec 2026 (outside 180 day window)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-dec',
            'name' => 'December Task',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Open',
            'created_time' => 'Dec 25, 2026 09:00 AM',
        ]);

        // Filter range spanning 186 days (Jan 01, 2026 to Jul 31, 2026)
        $request = new Request([
            'start_date' => '2026-01-01',
            'end_date' => '2026-07-31',
        ]);
        $dto = DashboardFilterDTO::fromRequest($request);

        $query = ClickUpTaskCache::query();
        $this->filterService->applyFilters($query, $dto);
        $results = $query->get();

        $this->assertCount(2, $results);
        $taskIds = $results->pluck('clickup_task_id')->toArray();
        $this->assertContains('task-jan', $taskIds);
        $this->assertContains('task-july', $taskIds);
        $this->assertNotContains('task-dec', $taskIds);
    }

    public function test_custom_date_range_spanning_multi_years(): void
    {
        // Task in 2024
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-2024',
            'name' => '2024 Task',
            'tipe_aplikasi' => 'EBESHA',
            'status' => 'Closed',
            'created_time' => 'Nov 05, 2024 11:00 AM',
        ]);

        // Task in 2025
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-2025',
            'name' => '2025 Task',
            'tipe_aplikasi' => 'EBESHA',
            'status' => 'Open',
            'created_time' => 'Jun 12, 2025 04:00 PM',
        ]);

        // Filter 2 year range: 2024-01-01 to 2025-12-31 (730 days)
        $request = new Request([
            'start_date' => '2024-01-01',
            'end_date' => '2025-12-31',
        ]);
        $dto = DashboardFilterDTO::fromRequest($request);

        $query = ClickUpTaskCache::query();
        $this->filterService->applyFilters($query, $dto);
        $results = $query->get();

        $this->assertCount(2, $results);
    }

    public function test_module_aplikasi_and_technician_filtering(): void
    {
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-1',
            'name' => 'Cafeins Bug',
            'tipe_aplikasi' => 'CAFEINS',
            'aplikasi' => 'CAFEINS Web',
            'status' => 'Open',
            'technician' => 'LMD - Louis',
            'created_time' => 'Aug 01, 2026 10:00 AM',
        ]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-2',
            'name' => 'PSA Error',
            'tipe_aplikasi' => 'PSA PCA',
            'aplikasi' => 'PSA Core',
            'status' => 'Closed',
            'technician' => 'LMD - Aldi',
            'created_time' => 'Aug 02, 2026 11:00 AM',
        ]);

        // Filter by technician LMD - Louis
        $dto = DashboardFilterDTO::fromRequest(new Request(['technician' => 'LMD - Louis']));
        $query = ClickUpTaskCache::query();
        $this->filterService->applyFilters($query, $dto);
        $this->assertEquals(1, $query->count());
        $this->assertEquals('task-1', $query->first()->clickup_task_id);
    }

    public function test_get_available_filters_returns_distinct_options_fast(): void
    {
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-opt-1',
            'name' => 'Opt Task 1',
            'tipe_aplikasi' => 'CAFEINS',
            'aplikasi' => 'CAFEINS Mobile',
            'status' => 'Open',
            'technician' => 'LMD - Louis',
            'created_time' => 'Aug 10, 2026 10:00 AM',
        ]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-opt-2',
            'name' => 'Opt Task 2',
            'tipe_aplikasi' => 'PSA PCA',
            'aplikasi' => 'PSA Web',
            'status' => 'Closed',
            'technician' => 'LMD - Aldi',
            'created_time' => 'Jul 20, 2026 11:00 AM',
        ]);

        $available = $this->filterService->getAvailableFilters();

        $this->assertContains('CAFEINS', $available['modules']);
        $this->assertContains('PSA PCA', $available['modules']);
        $this->assertContains('CAFEINS Mobile', $available['applications']);
        $this->assertContains('LMD - Louis', $available['technicians']);
        $this->assertContains('Open', $available['statuses']);
        $this->assertContains('Closed', $available['statuses']);
        $this->assertIsArray($available['periods']);
    }
}
