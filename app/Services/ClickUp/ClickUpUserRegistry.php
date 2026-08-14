<?php

namespace App\Services\ClickUp;

class ClickUpUserRegistry
{
    /**
     * Known ClickUp Users in Workspace with their exact User IDs.
     */
    public const USERS = [
        [
            'id' => 113406558,
            'name' => 'Muhammad Dzaka Murran',
            'email' => 'dzaka@lmd.co.id',
            'initials' => 'MM',
            'role' => 'Main Apps Specialist',
            'default_category' => 'MAIN',
        ],
        [
            'id' => 95657721,
            'name' => 'Mukhlis Ibrahim',
            'email' => 'mukhlis@lmd.co.id',
            'initials' => 'MI',
            'role' => 'Infrastructure Specialist',
            'default_category' => 'INFRA',
        ],
        [
            'id' => 95553944,
            'name' => 'Support LMD',
            'email' => 'support@lmd.co.id',
            'initials' => 'SL',
            'role' => 'Support Lead',
            'default_category' => 'ALL',
        ],
        [
            'id' => 95657720,
            'name' => 'Ilyas Awaludin',
            'email' => 'ilyas@lmd.co.id',
            'initials' => 'IA',
            'role' => 'Support Member',
            'default_category' => 'MAIN',
        ],
        [
            'id' => 282628817,
            'name' => 'Jordi Alexander',
            'email' => 'jordi@lmd.co.id',
            'initials' => 'JA',
            'role' => 'Administrator',
            'default_category' => 'ALL',
        ],
        [
            'id' => 282624016,
            'name' => 'Erick Wijaya',
            'email' => 'erick.wijaya@lintasmediadanawa.com',
            'initials' => 'EW',
            'role' => 'Owner',
            'default_category' => 'ALL',
        ],
    ];

    /**
     * Get all registered ClickUp users.
     */
    public static function getAll(): array
    {
        return self::USERS;
    }

    /**
     * Find user by ID or Email.
     */
    public static function find(int|string $identifier): ?array
    {
        foreach (self::USERS as $user) {
            if ($user['id'] == $identifier || strtolower($user['email']) === strtolower((string) $identifier)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Default User IDs for Main Apps (Dzaka + Support).
     */
    public static function defaultMainAppAssignees(): array
    {
        return [113406558, 95553944];
    }

    /**
     * Default User IDs for Infrastructure Apps (Mukhlis + Support).
     */
    public static function defaultInfraAppAssignees(): array
    {
        return [95657721, 95553944];
    }
}
