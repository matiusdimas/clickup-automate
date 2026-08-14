<?php

namespace Tests\Unit;

use App\Services\ClickUp\ClickUpApiClient;
use App\Services\ClickUp\ClickUpImportService;
use App\Services\ClickUp\ClickUpSyncService;
use App\Services\ClickUp\ImportNormalizerService;
use PHPUnit\Framework\TestCase;

class ClickUpImportServiceTest extends TestCase
{
    public function test_normalizer_service_sdp_technician_initial_prioritization(): void
    {
        $normalizer = new ImportNormalizerService();
        
        $sdpRow = [
            'nomor_tiket' => '12345',
            'subject' => 'Issue DB',
            'technician' => 'John Full Name',
            'inisial' => 'JFN',
        ];

        $payloadSdp = $normalizer->normalizeImportRow($sdpRow, [], [], 'sdp');
        $payloadEbesha = $normalizer->normalizeImportRow($sdpRow, [], [], 'ebesha');

        $this->assertEquals('JFN', $payloadSdp['technician']);
        $this->assertEquals('John Full Name', $payloadEbesha['technician']);
    }

    public function test_service_initialization_with_mocks(): void
    {
        $apiClient = $this->createMock(ClickUpApiClient::class);
        $syncService = $this->createMock(ClickUpSyncService::class);
        $normalizer = new ImportNormalizerService();

        $importService = new ClickUpImportService($apiClient, $normalizer, $syncService);

        $this->assertInstanceOf(ClickUpImportService::class, $importService);
        $this->assertFalse($importService->isImportCancelled(null));
    }
}
