<?php

namespace Database\Seeders;

use App\Support\Permissions\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The panel's permissions and its two first roles (more are made in «الأدوار والصلاحيات»). */
class StaffRolesSeeder extends Seeder
{
    public const ADMIN = 'مشرف عام';

    public const CONTENT = 'مشرف المحتوى';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        Role::findOrCreate(self::ADMIN, 'web')->syncPermissions(array_map(fn (Permission $p) => $p->value, Permission::cases()));
        Role::findOrCreate(self::CONTENT, 'web')->syncPermissions(array_map(fn (Permission $p) => $p->value, Permission::contentModerator()));
    }
}
