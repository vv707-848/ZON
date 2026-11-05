<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\SessionsRelationManager;
use App\Models\Customer;
use App\Models\CustomerDevice;
use App\Support\Permissions\Permission;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** One session per phone: the account sees its phones and ends one of them; the push registration goes with it. */
class SessionsTest extends TestCase
{
    /** Signs in from one phone (a real token, as the app gets it) and registers its push token. */
    private function phone(string $name, string $push): string
    {
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'faisal', 'password' => 'a-good-secret', 'device_name' => $name,
            'push_token' => $push, 'platform' => 'ios', 'app_version' => '3.1.2'])->assertOk()->json('data.token');
        Auth::forgetGuards();

        return (string) $token;
    }

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_the_account_sees_its_phones_and_ends_a_lost_one_with_its_pushes(): void
    {
        $me = Customer::factory()->seeker()->create(['username' => 'faisal', 'password' => 'a-good-secret']);
        $mine = $this->phone('iPhone 16', 'push-mine');
        $lost = $this->phone('iPad', 'push-lost');

        $sessions = $this->as($mine)->getJson('/api/v1/me/sessions')->assertOk()->json('data');
        $this->assertSame(['iPhone 16', 'iPad'], array_column($sessions, 'device'), 'the phone in use first');
        $this->assertSame([true, false], array_column($sessions, 'current'));
        $this->assertSame(['ios', '3.1.2'], [$sessions[1]['platform'], $sessions[1]['app_version']]);

        $this->as($mine)->deleteJson('/api/v1/me/sessions/'.$sessions[1]['id'])->assertOk();

        $this->as($lost)->getJson('/api/v1/me')->assertUnauthorized();
        $this->as($mine)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['push-mine'], CustomerDevice::query()->where('customer_id', $me->id)->pluck('push_token')->all(),
            'the lost phone no longer gets the account\'s notifications');
    }

    public function test_another_accounts_session_does_not_exist_and_registering_a_phone_ties_it_to_this_session(): void
    {
        Customer::factory()->seeker()->create(['username' => 'faisal', 'password' => 'a-good-secret']);
        $other = Customer::factory()->seeker()->create();
        $theirs = $other->createToken('Galaxy')->accessToken;
        $mine = $this->phone('iPhone 16', 'push-mine');

        $this->as($mine)->deleteJson("/api/v1/me/sessions/{$theirs->getKey()}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertNotNull($theirs->fresh());

        $this->as($mine)->postJson('/api/v1/me/devices', ['push_token' => 'push-new', 'platform' => 'android'])->assertOk();
        $session = $this->as($mine)->getJson('/api/v1/me/sessions')->json('data.0.id');
        $this->assertSame($session, CustomerDevice::query()->where('push_token', 'push-new')->value('access_token_id'));
    }

    public function test_the_panel_ends_one_phones_session_and_audits_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $customer = Customer::factory()->seeker()->create();
        $stolen = $customer->createToken('iPhone قديم')->accessToken;
        $kept = $customer->createToken('iPhone 16')->accessToken;
        $staff = $this->staff();
        $staff->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $role = Role::findOrCreate('support-'.$staff->id, 'web');
        $role->syncPermissions([Permission::ViewCustomers->value, Permission::ManageCustomers->value]);
        $staff->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($staff, 'web');

        Livewire::test(SessionsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$stolen, $kept])
            ->callTableAction('end', $stolen)->assertHasNoTableActionErrors();

        $this->assertNull($stolen->fresh());
        $this->assertNotNull($kept->fresh());
        $this->assertDatabaseHas('activity_log', ['event' => 'session_ended', 'subject_id' => $customer->id]);
    }
}
