<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            PaymentMethodSeeder::class,
            AllergenReferenceSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }
}
