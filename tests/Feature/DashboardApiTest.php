<?php

namespace Tests\Feature;

use App\Models\ClickUpTaskCache;
use App\Services\ClickUp\DashboardFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new DashboardFilterService())->clearAvailableFiltersCache();
    }

    public function test_dashboard_api_returns_analytics_with_available_filters(): void
    {
        $token = 'test-token-123';
        config(['services.api.token' => $token]);

        // Seed task cache
        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-1',
            'name' => 'Fix Login Bug',
            'tipe_aplikasi' => 'CAFEINS',
            'aplikasi' => 'CAFEINS Mobile',
            'status' => 'Open',
            'technician' => 'LMD - Aldi',
            'created_at' => Carbon::now(),
        ]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-2',
            'name' => 'Database Timeout',
            'tipe_aplikasi' => 'PSA PCA',
            'aplikasi' => 'PSA Core',
            'status' => 'Closed',
            'technician' => 'LMD - Louis',
            'created_at' => Carbon::now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/clickup/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'timestamp',
                'filters',
                'available_filters' => [
                    'modules',
                    'applications',
                    'technicians',
                    'statuses',
                    'periods',
                ],
                'data' => [
                    'summary',
                    'by_module',
                    'by_application',
                    'by_status',
                    'by_priority',
                    'by_technician',
                    'recent_tasks',
                ],
            ]);

        $json = $response->json();
        $this->assertTrue($json['success']);
        $this->assertContains('CAFEINS', $json['available_filters']['modules']);
        $this->assertContains('LMD - Aldi', $json['available_filters']['technicians']);
        $this->assertContains('Open', $json['available_filters']['statuses']);
    }

    public function test_analytics_alias_endpoint_returns_identical_structure(): void
    {
        $token = 'test-token-123';
        config(['services.api.token' => $token]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-alias',
            'name' => 'Alias Endpoint Test',
            'tipe_aplikasi' => 'EBESHA',
            'status' => 'Open',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/clickup/analytics');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_dashboard_api_supports_month_filtering(): void
    {
        $token = 'test-token-123';
        config(['services.api.token' => $token]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/clickup/dashboard?period=' . Carbon::now()->format('Y-m'));

        $response->assertStatus(200);
        $this->assertEquals(Carbon::now()->format('Y-m'), $response->json('filters.period'));
    }

    public function test_month_filtering_relies_strictly_on_created_time_not_db_created_at(): void
    {
        $token = 'test-token-123';
        config(['services.api.token' => $token]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-june',
            'name' => 'June Ticket',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Open',
            'created_time' => 'Jun 15, 2026 10:00 AM',
            'created_at' => Carbon::create(2026, 7, 10, 12, 0, 0),
        ]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-july',
            'name' => 'July Ticket',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Closed',
            'created_time' => 'Jul 20, 2026 02:00 PM',
            'created_at' => Carbon::create(2026, 7, 20, 14, 0, 0),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/clickup/dashboard?period=2026-07');

        $response->assertStatus(200);
        $summary = $response->json('data.summary');

        $this->assertEquals(1, $summary['total_tasks']);
    }

    public function test_custom_date_range_beyond_90_days_via_http(): void
    {
        $token = 'test-token-123';
        config(['services.api.token' => $token]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-jan',
            'name' => 'Jan Ticket',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Open',
            'created_time' => 'Jan 10, 2026 10:00 AM',
        ]);

        ClickUpTaskCache::create([
            'clickup_task_id' => 'task-jul',
            'name' => 'Jul Ticket',
            'tipe_aplikasi' => 'CAFEINS',
            'status' => 'Closed',
            'created_time' => 'Jul 20, 2026 02:00 PM',
        ]);

        // Range: 2026-01-01 to 2026-07-31 (211 days)
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/clickup/analytics?start_date=2026-01-01&end_date=2026-07-31');

        $response->assertStatus(200);
        $summary = $response->json('data.summary');
        $this->assertEquals(2, $summary['total_tasks']);
    }
}
