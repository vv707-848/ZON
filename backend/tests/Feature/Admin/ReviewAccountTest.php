<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\MakeReviewAccount;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Support\Permissions\Permission;
use Livewire\Livewire;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/** «حساب مراجعة»: the superadmin alone makes an account for the stores' reviewers — sandboxed, its password shown once. */
class ReviewAccountTest extends AdminTestCase
{
    private const FORM = ['role' => 'seeker', 'name' => 'مراجعة المتجر', 'username' => 'zon.review', 'phone' => '0551234567', 'city' => 'الرياض'];

    public function test_the_superadmin_makes_one_and_reads_its_password_once(): void
    {
        $this->signInSuperadmin();

        Livewire::test(ListCustomers::class)->callAction('review_account', self::FORM)->assertHasNoActionErrors()
            ->assertNotified('حساب المراجعة جاهز — انسخ كلمة السر الآن، لن تظهر مرة أخرى');

        $account = Customer::query()->where('username', 'zon.review')->sole();
        $this->assertSame([true, '+966551234567', 'active', 'seeker'], [$account->is_tester, $account->phone, $account->status->value, $account->role->value]);
        $audit = Activity::query()->where('event', 'review_account_created')->sole();
        $this->assertSame(['role' => 'seeker', 'username' => 'zon.review'], $audit->properties->except('trace_id')->all());
    }

    public function test_the_password_signs_in_and_a_new_one_signs_out_everywhere(): void
    {
        $superadmin = $this->signInSuperadmin();
        [$account, $password] = app(MakeReviewAccount::class)([...self::FORM, 'phone' => '+966551234567'], $superadmin);

        $this->postJson('/api/v1/auth/login', ['login' => 'zon.review', 'password' => $password])->assertOk()->assertJsonPath('data.me.is_tester', true);
        $this->assertSame(1, $account->tokens()->count());

        Livewire::test(ViewCustomer::class, ['record' => $account->getRouteKey()])->callAction('review_password')->assertHasNoActionErrors()
            ->assertNotified('كلمة السر الجديدة — انسخها الآن، لن تظهر مرة أخرى');
        $this->assertSame(0, $account->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['login' => 'zon.review', 'password' => $password])->assertStatus(401);
        $this->assertSame(0, Activity::query()->where('properties', 'like', "%{$password}%")->count(), 'no password is in any log');

        $this->expectException(LogicException::class);
        app(MakeReviewAccount::class)->newPassword(Customer::factory()->create(), $superadmin);   // a real account's password is its own
    }

    public function test_no_one_else_makes_one_and_no_form_turns_a_real_account_into_one(): void
    {
        $real = Customer::factory()->create();
        $this->signInStaff(Permission::ViewCustomers, Permission::ManageCustomers, Permission::SuspendCustomers);

        Livewire::test(ListCustomers::class)->assertActionHidden('review_account');
        Livewire::test(ViewCustomer::class, ['record' => Customer::factory()->tester()->create()->getRouteKey()])->assertActionHidden('review_password');
        Livewire::test(EditCustomer::class, ['record' => $real->getRouteKey()])->assertFormFieldDoesNotExist('is_tester');

        $this->signInSuperadmin();
        Livewire::test(EditCustomer::class, ['record' => $real->getRouteKey()])->assertFormFieldDoesNotExist('is_tester');
        Livewire::test(ListCustomers::class)->callAction('review_account', [...self::FORM, 'phone' => $real->phone])
            ->assertHasActionErrors(['phone']);
        $this->assertFalse($real->fresh()->is_tester);
    }

    public function test_a_review_broker_is_checked_by_rega_like_anyone(): void
    {
        $superadmin = $this->signInSuperadmin();

        [$broker] = app(MakeReviewAccount::class)([...self::FORM, 'role' => 'broker', 'username' => 'zon.review.broker', 'phone' => '+966551234568', 'fal_number' => '1100012345'], $superadmin);

        $this->assertSame('valid', $broker->fresh()->fal_status->value, 'the simulated REGA answered the queued check');
        $this->expectException(LogicException::class);
        app(MakeReviewAccount::class)([...self::FORM, 'role' => 'developer', 'username' => 'zon.review.dev', 'phone' => '+966551234569'], $superadmin);
    }
}
