<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What every environment needs (reference data, plans, panel roles, the first knowledge documents).
 * Demo accounts and listings come only in local (DemoSeeder). The first superadmin: php artisan zon:create-superadmin.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ReferenceSeeder::class, PackageSeeder::class, StaffRolesSeeder::class, KnowledgeSeeder::class]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
