<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the default roles and permissions once, after the database is migrated.
     */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    /**
     * Tests must not depend on the developer's or the CI server's .env. A few tests change
     * these themselves when they specifically cover timezone behaviour.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'incidents.display_timezone' => 'UTC',
            'incidents.settings.display_timezone' => 'UTC',
        ]);
    }
}
