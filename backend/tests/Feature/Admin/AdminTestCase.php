<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Permissions\Permission;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Panel tests: staff with an authenticator already set up (the panel requires one before any screen). */
abstract class AdminTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function signInStaff(Permission ...$permissions): User
    {
        $user = User::factory()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $role = Role::findOrCreate('test-role-'.$user->id, 'web');
        $role->syncPermissions(array_map(fn (Permission $p) => $p->value, $permissions));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user, 'web');

        return $user;
    }

    protected function signInSuperadmin(): User
    {
        $user = User::factory()->superadmin()->create(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->actingAs($user, 'web');

        return $user;
    }
}
