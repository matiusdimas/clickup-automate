<?php

namespace Tests\Unit;

use App\DTOs\DashboardFilterDTO;
use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\DashboardAnalyticsService;
use App\Services\ClickUp\DashboardFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardAnalyticsService $analyticsService;
    private DashboardFilterService $filterService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filterService = new DashboardFilterService();
        $this->filterService->clearAvailableFiltersCache();
        $this->analyticsService = new DashboardAnalyticsService($this->filterService);
    }

    public function test_get_analytics_returns_correct_metrics_structure_and_counts(): void
    {
        // Seed Task 1 (Open, CAFEINS)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-1',
            'name' => 'Login Failure',
            'tipe_aplikasi' => 'CAFEINS',
            'aplikasi' => 'CAFEINS Mobile',
            'status' => 'Open',
            'priority' => 'Urgent',
            'technician' => 'LMD - Louis',
            'created_time' => 'Aug 01, 2026 10:00 AM',
            'updated_at' => Carbon::now(),
        ]);

        // Seed Task 2 (Closed, CAFEINS)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-2',
            'name' => 'Session Timeout',
            'tipe_aplikasi' => 'CAFEINS',
            'aplikasi' => 'CAFEINS Mobile',
            'status' => 'Closed',
            'priority' => 'High',
            'technician' => 'LMD - Louis',
            'created_time' => 'Aug 02, 2026 11:00 AM',
            'updated_at' => Carbon::now(),
        ]);

        // Seed Task 3 (Closed, PSA PCA)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-3',
            'name' => 'Database Lag',
            'tipe_aplikasi' => 'PSA PCA',
            'aplikasi' => 'PSA Core',
            'status' => 'Resolved',
            'priority' => 'Normal',
            'technician' => 'LMD - Aldi',
            'created_time' => 'Aug 03, 2026 12:00 PM',
            'updated_at' => Carbon::now(),
        ]);

        $dto = DashboardFilterDTO::fromRequest(new Request(['period' => 'all']));
        $analytics = $this->analyticsService->getAnalytics($dto);

        $this->assertTrue($analytics['success']);
        $this->assertArrayHasKey('data', $analytics);

        $summary = $analytics['data']['summary'];
        $this->assertEquals(3, $summary['total_tasks']);
        $this->assertEquals(1, $summary['open_tasks']);
        $this->assertEquals(2, $summary['closed_tasks']);
        $this->assertEquals(66.7, $summary['resolution_rate_pct']);

        // Check module breakdown
        $byModule = collect($analytics['data']['by_module']);
        $cafeinsMod = $byModule->firstWhere('tipe_aplikasi', 'CAFEINS');
        $this->assertNotNull($cafeinsMod);
        $this->assertEquals(2, $cafeinsMod['total_tasks']);
        $this->assertEquals(1, $cafeinsMod['open_tasks']);
        $this->assertEquals(1, $cafeinsMod['closed_tasks']);
        $this->assertEquals(50.0, $cafeinsMod['resolution_rate_pct']);

        // Check technician breakdown
        $byTech = collect($analytics['data']['by_technician']);
        $louisTech = $byTech->firstWhere('technician', 'LMD - Louis');
        $this->assertNotNull($louisTech);
        $this->assertEquals(2, $louisTech['total_tasks']);
        $this->assertEquals(1, $louisTech['closed_tasks']);
    }

    public function test_get_analytics_with_date_range_greater_than_90_days(): void
    {
        // Task 180 days ago (Feb 2026)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-feb',
            'name' => 'Old Feb Ticket',
            'tipe_aplikasi' => 'EBESHA',
            'status' => 'Closed',
            'created_time' => 'Feb 15, 2026 09:00 AM',
            'updated_at' => Carbon::now(),
        ]);

        // Task recent (Aug 2026)
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-aug',
            'name' => 'Recent Aug Ticket',
            'tipe_aplikasi' => 'EBESHA',
            'status' => 'Open',
            'created_time' => 'Aug 05, 2026 10:00 AM',
            'updated_at' => Carbon::now(),
        ]);

        // Range: 2026-02-01 to 2026-08-31 (211 days)
        $request = new Request([
            'start_date' => '2026-02-01',
            'end_date' => '2026-08-31',
        ]);
        $dto = DashboardFilterDTO::fromRequest($request);

        $analytics = $this->analyticsService->getAnalytics($dto);

        $this->assertTrue($analytics['success']);
        $summary = $analytics['data']['summary'];
        $this->assertEquals(2, $summary['total_tasks']);
        $this->assertEquals(1, $summary['closed_tasks']);
        $this->assertEquals(1, $summary['open_tasks']);
    }
}
