<?php

namespace Tests\Unit;

use App\Services\ClickUp\ClickUpAppRegistry;
use PHPUnit\Framework\TestCase;

class ClickUpAppRegistryTest extends TestCase
{
    public function test_get_options_returns_all_36_options(): void
    {
        $options = ClickUpAppRegistry::getOptions();

        $this->assertCount(36, $options);
        $this->assertEquals('aec0cf66-4c70-41e1-9b61-311d4d1a8eb5', ClickUpAppRegistry::FIELD_ID);
        $this->assertEquals('Apps', ClickUpAppRegistry::FIELD_NAME);
    }

    public function test_map_app_category_maps_all_36_app_names_correctly(): void
    {
        $expectedMappings = [
            'Cafeins' => 'bbe04f86-d669-4216-9d74-50b06d57c920',
            'Sales Mastes' => '66596674-9673-4b1a-be26-6192038774dc',
            'CMMS' => 'ed3788b1-277c-4c32-b130-9379356ee3e0',
            'MyLA' => 'f80b800d-54aa-4389-b2fb-cdf4c623f72a',
            'PSA PCA' => 'd053f47c-d816-4caf-b91c-88372f9d3b27',
            'PMOIS' => 'cfb962e5-71f3-4609-9b56-c8b784ccb325',
            'Doc Tracking' => '655932d5-1747-442e-9619-e74f44592cc2',
            'Starla' => 'b015af83-26e5-48eb-bedc-d326c9145ab8',
            'eBesha' => '730a53d7-3658-4fd6-aa4e-89fa91bf3a1b',
            'Ultima & Starlink' => 'acc57591-7221-4118-8e03-d366d9a76be4',
            'GNTU' => '099e4653-4973-4da1-8cb4-ec709af6f812',
            'Jarin' => 'd225ea0a-7258-4d94-b952-6dc73b33dc01',
            'Infra - cPanel Arint' => 'fabafe63-82c0-409f-beb9-3522b282fd36',
            'Infra - cPanel Kominfo' => '86a245fb-7737-4d86-a8e9-22f480aec888',
            'Infra - cPanel Hanken' => 'f7abb889-6bec-43f3-aafc-092828765117',
            'Infra - cPanel Kalteng' => '8f629dc0-f198-461a-a2cb-0e08bd96f6a1',
            'Infra - Cafeins LA' => '3361634b-6603-47fa-b80a-c1bd504a061e',
            'Infra - RCS LA' => '4700c5db-40d2-4569-9503-036716d1c3b7',
            'Infra - cPanel Yakult' => '4133b96d-2f6f-4d13-8e1a-12873707ff1e',
            'Infra - AD BPD NTB' => '7c787c4b-1e9f-4a97-8a37-3f2755cee3c5',
            'Infra - AD IOH' => '3435f015-37d9-4046-b9ba-41301a5fc006',
            'Infra - Patch Manager LA' => '1af009bb-4a41-4912-85d6-66b62db41bdb',
            'Infra - Odoo Jarin' => '3cd2bda8-7da4-484e-b45e-540a14bd4b3f',
            'Infra - Bank Mestika' => '71d081bd-b10c-41c1-825a-45508302c6fd',
            'Infra - MyLA/PMOIS LA' => 'c600a542-971c-4cf3-90c2-849c5191e54c',
            'Infra - PSA/PCA LA' => 'e4077819-7319-43d5-8613-03ec350bfcfe',
            'Infra - CMMS LA' => '70901152-457f-4c77-9bdf-d55f1a48cbe3',
            'Infra - Contact Center' => '8a0792fd-6279-40b1-80f0-ca07d0c9d933',
            'Infra - APL ' => '79f48032-b4ee-4a2e-8777-2121c06b01fa',
            'Infra - Owlexa LA' => 'a1158ebf-5abe-4ba4-840a-24a56edcbbba',
            'Infra - IT Corp LA' => '9904188a-fbd3-4dd0-8b79-6bbc259e0249',
            'Infra - Cloudeka LA' => '3b4117dc-2753-45ea-a21b-d3f07534748e',
            'Infra - Kargo Oke' => '09bfa7ba-996c-44e9-9017-d109df4fcdad',
            'Infra - Primecare Hospital' => '0c17f133-8621-49b5-beaf-fbefe37a16c5',
            'Infra - Ultima/Starlink LA' => 'c419a4be-fe63-4c78-b388-0e805af4721e',
            'Infra - GNTU' => '5fccad63-21da-4a7c-b0a3-4e8cc1ef8a16',
        ];

        foreach ($expectedMappings as $appName => $expectedId) {
            $mappedId = ClickUpAppRegistry::mapAppCategory($appName);
            $this->assertEquals($expectedId, $mappedId, "Failed asserting mapping for app: {$appName}");
        }
    }

    public function test_map_app_category_handles_case_insensitivity_and_whitespace(): void
    {
        $this->assertEquals('bbe04f86-d669-4216-9d74-50b06d57c920', ClickUpAppRegistry::mapAppCategory('  cafeins  '));
        $this->assertEquals('fabafe63-82c0-409f-beb9-3522b282fd36', ClickUpAppRegistry::mapAppCategory('infra - cpanel arint'));
        $this->assertEquals('acc57591-7221-4118-8e03-d366d9a76be4', ClickUpAppRegistry::mapAppCategory('ultima & starlink'));
        $this->assertEquals('acc57591-7221-4118-8e03-d366d9a76be4', ClickUpAppRegistry::mapAppCategory('Ultima/Starlink'));
    }

    public function test_map_app_category_returns_null_for_unknown_apps(): void
    {
        $this->assertNull(ClickUpAppRegistry::mapAppCategory('NonExistentApp123'));
        $this->assertNull(ClickUpAppRegistry::mapAppCategory(null));
        $this->assertNull(ClickUpAppRegistry::mapAppCategory(''));
    }

    public function test_get_option_name_by_id_and_index(): void
    {
        $this->assertEquals('Cafeins', ClickUpAppRegistry::getOptionNameById('bbe04f86-d669-4216-9d74-50b06d57c920'));
        $this->assertEquals('Infra - GNTU', ClickUpAppRegistry::getOptionNameById('5fccad63-21da-4a7c-b0a3-4e8cc1ef8a16'));

        $this->assertEquals('Cafeins', ClickUpAppRegistry::getOptionNameByIndex(0));
        $this->assertEquals('Infra - GNTU', ClickUpAppRegistry::getOptionNameByIndex(35));
    }
}
