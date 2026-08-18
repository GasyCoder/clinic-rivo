<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissionIds = Permission::query()->pluck('id');

        Role::query()->where('code', 'SUPER_ADMIN')->first()
            ?->permissions()->sync($allPermissionIds);

        $userManagementIds = Permission::query()
            ->whereIn('name', array_keys(PermissionSeeder::PERMISSIONS))
            ->pluck('id');

        Role::query()->where('code', 'ADMINISTRATION')->first()
            ?->permissions()->sync($userManagementIds);
    }
}
