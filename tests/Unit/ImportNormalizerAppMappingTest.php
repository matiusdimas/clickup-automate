<?php

namespace Tests\Unit;

use App\Services\ClickUp\ImportNormalizerService;
use PHPUnit\Framework\TestCase;

class ImportNormalizerAppMappingTest extends TestCase
{
    private ImportNormalizerService $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ImportNormalizerService();
    }

    public function test_normalizer_delegates_map_app_category(): void
    {
        $this->assertEquals('bbe04f86-d669-4216-9d74-50b06d57c920', $this->normalizer->mapAppCategory('Cafeins'));
        $this->assertEquals('fabafe63-82c0-409f-beb9-3522b282fd36', $this->normalizer->mapAppCategory('Infra - cPanel Arint'));
        $this->assertEquals('5fccad63-21da-4a7c-b0a3-4e8cc1ef8a16', $this->normalizer->mapAppCategory('Infra - GNTU'));
    }

    public function test_normalizer_handles_unknown_apps(): void
    {
        $this->assertNull($this->normalizer->mapAppCategory('Unknown App'));
    }

    public function test_normalizer_extracts_technician_from_created_by_column(): void
    {
        $excelRow = [
            'nomor_tiket' => 'LMD/2026/8/6951',
            'created_by' => 'hendrik.louis@lmd.co.id',
            'initial_time' => 'Aug 06, 2026 01:56 PM',
            'subject' => 'Kendala softphone',
        ];

        $normalized = $this->normalizer->normalizeImportRow($excelRow);

        $this->assertEquals('hendrik.louis@lmd.co.id', $normalized['technician']);
        $this->assertEquals('Aug 06, 2026 01:56 PM', $normalized['response_date']);
    }

    public function test_normalizer_extracts_technician_from_sdp_inisial_column(): void
    {
        $excelRowSdp = [
            'nomor_tiket' => 'LMD/2026/3/1001',
            'inisial' => 'Matius.Prasetia',
            'subject' => 'Kendala server',
        ];

        $techMappings = [
            (object) ['original_name' => 'Matius.Prasetia', 'mapped_name' => 'LMD - Matius'],
        ];

        $normalized = $this->normalizer->normalizeImportRow($excelRowSdp, [], $techMappings);

        $this->assertEquals('LMD - Matius', $normalized['technician']);
    }

    public function test_normalizer_maps_technician_with_domain_variations(): void
    {
        $techMappings = [
            (object) ['original_name' => 'hendrik.louis@lintasmediadanawa.com', 'mapped_name' => 'LMD - Louis'],
            (object) ['original_name' => 'yana.nurrohman@lintasmediadanawa.com', 'mapped_name' => 'LMD - Yana'],
        ];

        $excelRow1 = ['created_by' => 'hendrik.louis@lmd.co.id'];
        $excelRow2 = ['created_by' => 'hendrik.louis'];
        $excelRow3 = ['created_by' => 'yana.nurrohman@lintasmediadanawa.com'];

        $res1 = $this->normalizer->normalizeImportRow($excelRow1, [], $techMappings);
        $res2 = $this->normalizer->normalizeImportRow($excelRow2, [], $techMappings);
        $res3 = $this->normalizer->normalizeImportRow($excelRow3, [], $techMappings);

        $this->assertEquals('LMD - Louis', $res1['technician']);
        $this->assertEquals('LMD - Louis', $res2['technician']);
        $this->assertEquals('LMD - Yana', $res3['technician']);
    }
}
