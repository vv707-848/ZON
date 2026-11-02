<?php

namespace Database\Seeders;

use App\Support\Permissions\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The panel's permissions and the supervisor's role (the superadmin makes more in «الأدوار والصلاحيات»). */
class StaffRolesSeeder extends Seeder
{
    public const SUPERVISOR = 'مشرف';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        Role::findOrCreate(self::SUPERVISOR, 'web')->syncPermissions(array_map(fn (Permission $p) => $p->value, Permission::supervisor()));
    }
}
