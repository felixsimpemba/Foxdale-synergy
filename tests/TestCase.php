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
}
