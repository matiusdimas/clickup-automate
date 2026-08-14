<?php

namespace App\Services\ClickUp;

use Illuminate\Support\Str;

class ClickUpAppRegistry
{
    public const FIELD_ID = 'aec0cf66-4c70-41e1-9b61-311d4d1a8eb5';
    public const FIELD_NAME = 'Apps';

    /**
     * Determine if an app name belongs to Infrastructure Apps.
     */
    public static function isInfraApp(string $appName): bool
    {
        $clean = strtolower(trim($appName));
        return str_starts_with($clean, 'infra') || str_contains($clean, 'infra -');
    }

    /**
     * Complete option list for ClickUp Apps custom field.
     *
     * @var array<int, array{id: string, name: string, color: string, orderindex: int}>
     */
    private const OPTIONS = [
        [
            'id' => 'bbe04f86-d669-4216-9d74-50b06d57c920',
            'name' => 'Cafeins',
            'color' => '#b6b6ff',
            'orderindex' => 0,
        ],
        [
            'id' => '66596674-9673-4b1a-be26-6192038774dc',
            'name' => 'Sales Mastes',
            'color' => '#edadc8',
            'orderindex' => 1,
        ],
        [
            'id' => 'ed3788b1-277c-4c32-b130-9379356ee3e0',
            'name' => 'CMMS',
            'color' => '#aec0f5',
            'orderindex' => 2,
        ],
        [
            'id' => 'f80b800d-54aa-4389-b2fb-cdf4c623f72a',
            'name' => 'MyLA',
            'color' => '#96c7f2',
            'orderindex' => 3,
        ],
        [
            'id' => 'd053f47c-d816-4caf-b91c-88372f9d3b27',
            'name' => 'PSA PCA',
            'color' => '#8dcec3',
            'orderindex' => 4,
        ],
        [
            'id' => 'cfb962e5-71f3-4609-9b56-c8b784ccb325',
            'name' => 'PMOIS',
            'color' => '#92ceac',
            'orderindex' => 5,
        ],
        [
            'id' => '655932d5-1747-442e-9619-e74f44592cc2',
            'name' => 'Doc Tracking',
            'color' => '#e9c162',
            'orderindex' => 6,
        ],
        [
            'id' => 'b015af83-26e5-48eb-bedc-d326c9145ab8',
            'name' => 'Starla',
            'color' => '#ffaa7d',
            'orderindex' => 7,
        ],
        [
            'id' => '730a53d7-3658-4fd6-aa4e-89fa91bf3a1b',
            'name' => 'eBesha',
            'color' => '#6647f0',
            'orderindex' => 8,
        ],
        [
            'id' => 'acc57591-7221-4118-8e03-d366d9a76be4',
            'name' => 'Ultima & Starlink',
            'color' => '#3e63dd',
            'orderindex' => 9,
        ],
        [
            'id' => '099e4653-4973-4da1-8cb4-ec709af6f812',
            'name' => 'GNTU',
            'color' => '#0091ff',
            'orderindex' => 10,
        ],
        [
            'id' => 'd225ea0a-7258-4d94-b952-6dc73b33dc01',
            'name' => 'Jarin',
            'color' => '#12a594',
            'orderindex' => 11,
        ],
        [
            'id' => 'fabafe63-82c0-409f-beb9-3522b282fd36',
            'name' => 'Infra - cPanel Arint',
            'color' => '#cecece',
            'orderindex' => 12,
        ],
        [
            'id' => '86a245fb-7737-4d86-a8e9-22f480aec888',
            'name' => 'Infra - cPanel Kominfo',
            'color' => '#646464',
            'orderindex' => 13,
        ],
        [
            'id' => 'f7abb889-6bec-43f3-aafc-092828765117',
            'name' => 'Infra - cPanel Hanken',
            'color' => '#8d8d8d',
            'orderindex' => 14,
        ],
        [
            'id' => '8f629dc0-f198-461a-a2cb-0e08bd96f6a1',
            'name' => 'Infra - cPanel Kalteng',
            'color' => '#30a46c',
            'orderindex' => 15,
        ],
        [
            'id' => '3361634b-6603-47fa-b80a-c1bd504a061e',
            'name' => 'Infra - Cafeins LA',
            'color' => '#f3aeaf',
            'orderindex' => 16,
        ],
        [
            'id' => '4700c5db-40d2-4569-9503-036716d1c3b7',
            'name' => 'Infra - RCS LA',
            'color' => '#dfafe3',
            'orderindex' => 17,
        ],
        [
            'id' => '4133b96d-2f6f-4d13-8e1a-12873707ff1e',
            'name' => 'Infra - cPanel Yakult',
            'color' => '#d1b9b0',
            'orderindex' => 18,
        ],
        [
            'id' => '7c787c4b-1e9f-4a97-8a37-3f2755cee3c5',
            'name' => 'Infra - AD BPD NTB',
            'color' => '#ffc53d',
            'orderindex' => 19,
        ],
        [
            'id' => '3435f015-37d9-4046-b9ba-41301a5fc006',
            'name' => 'Infra - AD IOH',
            'color' => '#f76808',
            'orderindex' => 20,
        ],
        [
            'id' => '1af009bb-4a41-4912-85d6-66b62db41bdb',
            'name' => 'Infra - Patch Manager LA',
            'color' => '#e5484d',
            'orderindex' => 21,
        ],
        [
            'id' => '3cd2bda8-7da4-484e-b45e-540a14bd4b3f',
            'name' => 'Infra - Odoo Jarin',
            'color' => '#e93d82',
            'orderindex' => 22,
        ],
        [
            'id' => '71d081bd-b10c-41c1-825a-45508302c6fd',
            'name' => 'Infra - Bank Mestika',
            'color' => '#ab4aba',
            'orderindex' => 23,
        ],
        [
            'id' => 'c600a542-971c-4cf3-90c2-849c5191e54c',
            'name' => 'Infra - MyLA/PMOIS LA',
            'color' => '#a18072',
            'orderindex' => 24,
        ],
        [
            'id' => 'e4077819-7319-43d5-8613-03ec350bfcfe',
            'name' => 'Infra - PSA/PCA LA',
            'color' => '#202020',
            'orderindex' => 25,
        ],
        [
            'id' => '70901152-457f-4c77-9bdf-d55f1a48cbe3',
            'name' => 'Infra - CMMS LA',
            'color' => '#2147A3',
            'orderindex' => 26,
        ],
        [
            'id' => '8a0792fd-6279-40b1-80f0-ca07d0c9d933',
            'name' => 'Infra - Contact Center',
            'color' => '#662BC6',
            'orderindex' => 27,
        ],
        [
            'id' => '79f48032-b4ee-4a2e-8777-2121c06b01fa',
            'name' => 'Infra - APL ',
            'color' => '#F5002C',
            'orderindex' => 28,
        ],
        [
            'id' => 'a1158ebf-5abe-4ba4-840a-24a56edcbbba',
            'name' => 'Infra - Owlexa LA',
            'color' => '#8B9AAB',
            'orderindex' => 29,
        ],
        [
            'id' => '9904188a-fbd3-4dd0-8b79-6bbc259e0249',
            'name' => 'Infra - IT Corp LA',
            'color' => '#B877E7',
            'orderindex' => 30,
        ],
        [
            'id' => '3b4117dc-2753-45ea-a21b-d3f07534748e',
            'name' => 'Infra - Cloudeka LA',
            'color' => '#DFA81F',
            'orderindex' => 31,
        ],
        [
            'id' => '09bfa7ba-996c-44e9-9017-d109df4fcdad',
            'name' => 'Infra - Kargo Oke',
            'color' => '#27B42A',
            'orderindex' => 32,
        ],
        [
            'id' => '0c17f133-8621-49b5-beaf-fbefe37a16c5',
            'name' => 'Infra - Primecare Hospital',
            'color' => '#2F67D1',
            'orderindex' => 33,
        ],
        [
            'id' => 'c419a4be-fe63-4c78-b388-0e805af4721e',
            'name' => 'Infra - Ultima/Starlink LA',
            'color' => '#3EFC16',
            'orderindex' => 34,
        ],
        [
            'id' => '5fccad63-21da-4a7c-b0a3-4e8cc1ef8a16',
            'name' => 'Infra - GNTU',
            'color' => '#A4633F',
            'orderindex' => 35,
        ],
    ];

    /**
     * Get all app options.
     *
     * @return array<int, array{id: string, name: string, color: string, orderindex: int}>
     */
    public static function getOptions(): array
    {
        return self::OPTIONS;
    }

    /**
     * Map application string to its ClickUp Option UUID.
     * Robust matching supports exact, squished, normalized lower, and alias matching.
     */
    public static function mapAppCategory(?string $appName): ?string
    {
        if (blank($appName)) {
            return null;
        }

        $clean = Str::of($appName)->trim()->lower()->squish()->toString();

        // 1. Direct normalized lookup map
        foreach (self::OPTIONS as $option) {
            $optNameLower = Str::of($option['name'])->trim()->lower()->squish()->toString();
            if ($clean === $optNameLower) {
                return $option['id'];
            }
        }

        // 2. Normalize special characters (&, /, -, etc.)
        $sanitizedInput = preg_replace('/[^a-z0-9]/', '', $clean);

        foreach (self::OPTIONS as $option) {
            $optSanitized = preg_replace('/[^a-z0-9]/', '', strtolower($option['name']));
            if ($sanitizedInput === $optSanitized) {
                return $option['id'];
            }
        }

        // 3. Prefix & alias variations (e.g., "infra cpanel arint" -> "Infra - cPanel Arint", "ultima/starlink" -> "Ultima & Starlink")
        $aliasMap = [
            'ultima starlink' => 'acc57591-7221-4118-8e03-d366d9a76be4',
            'ultima and starlink' => 'acc57591-7221-4118-8e03-d366d9a76be4',
            'sales master' => '66596674-9673-4b1a-be26-6192038774dc',
            'sales masters' => '66596674-9673-4b1a-be26-6192038774dc',
            'doc tracking system' => '655932d5-1747-442e-9619-e74f44592cc2',
            'myla pmois la' => 'c600a542-971c-4cf3-90c2-849c5191e54c',
            'psa pca la' => 'e4077819-7319-43d5-8613-03ec350bfcfe',
            'ultima starlink la' => 'c419a4be-fe63-4c78-b388-0e805af4721e',
        ];

        return $aliasMap[$clean] ?? null;
    }

    /**
     * Get Option name by Option UUID.
     */
    public static function getOptionNameById(?string $id): ?string
    {
        if (blank($id)) {
            return null;
        }

        foreach (self::OPTIONS as $option) {
            if ($option['id'] === $id) {
                return trim($option['name']);
            }
        }

        return null;
    }

    /**
     * Get Option name by orderindex or index.
     */
    public static function getOptionNameByIndex(int $index): ?string
    {
        foreach (self::OPTIONS as $option) {
            if ($option['orderindex'] === $index) {
                return trim($option['name']);
            }
        }

        return self::OPTIONS[$index]['name'] ?? null;
    }

    /**
     * Check if application name is recognized.
     */
    public static function isValidApp(?string $appName): bool
    {
        return self::mapAppCategory($appName) !== null;
    }
}
