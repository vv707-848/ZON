<?php

namespace Tests\Feature\Admin;

use App\Enums\CommissionStatus;
use App\Enums\ListingStatus;
use App\Enums\StaffType;
use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\EditCity;
use App\Filament\Resources\CommissionDues\Pages\CreateCommissionDue;
use App\Filament\Resources\MarketingContracts\Pages\EditMarketingContract;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Models\City;
use App\Models\CommissionDue;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\MarketingContract;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions\Permission;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/** The panel's forms that change money, permissions or reference data: each refuses what is not valid, and logs. */
class AdminFormsTest extends AdminTestCase
{
    private function soldBy(Customer $broker): Listing
    {
        return Listing::factory()->for($broker)->create(['status' => ListingStatus::Sold, 'sold_at' => now()->subDay(), 'title' => 'شقة مباعة '.$broker->id]);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return Testable<CreateCommissionDue>
     */
    private function record(array $form): Testable
    {
        return Livewire::test(CreateCommissionDue::class)->fillForm($form + ['sale_price' => 900000, 'brokerage_fee' => 22500, 'percent' => 10])->call('create');
    }

    public function test_a_free_brokers_share_is_recorded_once_and_only_for_his_sale(): void
    {
        $this->signInSuperadmin();
        $broker = Customer::factory()->broker()->create();
        $sale = $this->soldBy($broker);

        $this->record(['listing_id' => $sale->id, 'brokerage_fee' => 22500.505])->assertHasFormErrors(['brokerage_fee']);   // a third decimal is not rounded away
        $this->record(['listing_id' => $sale->id, 'brokerage_fee' => 950000])->assertHasFormErrors(['brokerage_fee']);      // more than the sale
        $this->record(['listing_id' => $sale->id])->assertHasNoFormErrors();
        $due = CommissionDue::query()->sole();
        $this->assertSame([225000, CommissionStatus::Due], [(int) $due->amount_halalas, $due->status], '10 % of 22,500 SAR');
        $this->assertSame(1, Activity::query()->where('event', 'commission_recorded')->count());

        $this->record(['listing_id' => $sale->id])->assertHasFormErrors(['listing_id']);                                     // once per sale
        $this->assertSame(1, CommissionDue::query()->count());

        $owners = Listing::factory()->for(Customer::factory()->owner()->create())->create(['status' => ListingStatus::Sold, 'sold_at' => now()]);
        $this->record(['listing_id' => $owners->id])->assertHasFormErrors(['listing_id']);                                   // an owner's sale is his case's
        $this->record(['listing_id' => Listing::factory()->published()->for($broker)->create()->id])->assertHasFormErrors(['listing_id']);   // not sold

        $subscribed = Customer::factory()->broker()->create();
        Subscription::query()->create(['customer_id' => $subscribed->id, 'package_id' => Package::query()->value('id'), 'source' => 'grant',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth()]);
        $this->record(['listing_id' => $this->soldBy($subscribed)->id])->assertHasFormErrors(['listing_id']);               // his plan covered the sale
        $this->assertDatabaseCount('commission_dues', 1);
    }

    public function test_a_packages_price_takes_two_decimals_at_most_and_is_logged(): void
    {
        $this->signInSuperadmin();
        $package = Package::query()->firstOrFail();
        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])->fillForm(['price_halalas' => '279.999'])->call('save')->assertHasFormErrors(['price_halalas']);
        $this->assertSame($package->price_halalas, $package->refresh()->price_halalas);

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])->fillForm(['price_halalas' => '279.50'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(27950, (int) $package->refresh()->price_halalas);
        $this->assertSame(27950, (int) Activity::query()->where('event', 'package_updated')->sole()->properties['changes']['price_halalas']);
    }

    public function test_the_last_active_superadmin_stays_one(): void
    {
        $me = $this->signInSuperadmin();
        Livewire::test(EditStaff::class, ['record' => $me->getRouteKey()])->fillForm(['type' => StaffType::Admin->value])->call('save')->assertHasFormErrors(['type']);
        $this->assertTrue($me->refresh()->isSuperadmin());

        $other = User::factory()->superadmin()->create();
        Livewire::test(EditStaff::class, ['record' => $other->getRouteKey()])->fillForm(['is_active' => false])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditStaff::class, ['record' => $me->getRouteKey()])->fillForm(['type' => StaffType::Admin->value])->call('save')->assertHasFormErrors(['type']);

        Livewire::test(EditStaff::class, ['record' => $other->getRouteKey()])->fillForm(['is_active' => true])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditStaff::class, ['record' => $me->getRouteKey()])->fillForm(['type' => StaffType::Admin->value])->call('save')->assertHasNoFormErrors();
        $this->assertFalse($me->refresh()->isSuperadmin(), 'with another one active, he may step down');
        $this->assertSame(['type'], Activity::query()->where('event', 'staff_updated')->latest('id')->first()->properties['fields']);
    }

    public function test_a_role_a_city_and_a_marketing_contract(): void
    {
        $this->signInSuperadmin();
        $view = \Spatie\Permission\Models\Permission::findOrCreate(Permission::ViewPayments->value, 'web')->id;
        Livewire::test(CreateRole::class)->fillForm(['name' => 'مراجع الدفعات', 'permissions' => [$view]])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Role::findByName('مراجع الدفعات', 'web')->hasPermissionTo(Permission::ViewPayments->value));
        $this->assertSame([Permission::ViewPayments->value], Activity::query()->where('event', 'role_created')->sole()->properties['permissions']);

        Livewire::test(CreateCity::class)->fillForm(['name' => 'الرياض', 'region' => City::query()->value('region')?->value])->call('create')->assertHasFormErrors(['name']);   // exists
        Livewire::test(CreateCity::class)->fillForm(['name' => 'العلا', 'region' => 'SA-03', 'is_active' => false])->call('create')->assertHasNoFormErrors();
        $city = City::query()->where('name', 'العلا')->sole();
        Livewire::test(EditCity::class, ['record' => $city->getRouteKey()])->fillForm(['is_active' => true, 'sort' => 5])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($city->refresh()->is_active);

        $contract = MarketingContract::query()->create(['developer_id' => Customer::factory()->developer()->create()->id, 'number' => 'MC-7', 'signed_at' => today(), 'commission_percent' => '2.00']);
        Livewire::test(EditMarketingContract::class, ['record' => $contract->getRouteKey()])->fillForm(['commission_percent' => 101])->call('save')->assertHasFormErrors(['commission_percent']);
        Livewire::test(EditMarketingContract::class, ['record' => $contract->getRouteKey()])->fillForm(['commission_percent' => '2.5'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2.50', (string) $contract->refresh()->commission_percent);
    }
}
