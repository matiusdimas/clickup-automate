<?php

namespace Tests\Feature;

use App\Models\ClickUpTaskCache;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_api_returns_analytics_with_available_filters()
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

    public function test_dashboard_api_supports_month_filtering()
    {
        $token = 'test-token-123';
        config(['services.api.token' => $token]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/clickup/dashboard?period=' . Carbon::now()->format('Y-m'));

        $response->assertStatus(200);
        $this->assertEquals(Carbon::now()->format('Y-m'), $response->json('filters.period'));
    }
}
