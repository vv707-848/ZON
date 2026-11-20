<?php

namespace Tests\Feature\Api;

use App\Actions\Admin\RefundPayment;
use App\Enums\BoostVia;
use App\Jobs\Scheduled\RemindSubscriptionRenewals;
use App\Jobs\SettleStorePurchase;
use App\Models\BoostUsage;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Subscription;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Buying in the apps through the stores, on the simulated stores (FakeStore): a purchase applies once, to the
 * account that made it, only on the store's word; renewals and refunds come from the stores' notices.
 */
class StorePurchasesTest extends TestCase
{
    private const SEEKER_PLAN = 'com.zon.app.seeker.month';

    private function seeker(bool $tester = false): Customer
    {
        $seeker = Customer::factory()->seeker()->create(['is_tester' => $tester]);
        BuyerPreference::factory()->create(['customer_id' => $seeker->id]);

        return $this->signIn($seeker);
    }

    /**
     * @param  ?array<string, mixed>  $target
     * @return TestResponse<Response>
     */
    private function buy(string $store, string $product, string $reference, ?array $target = null): TestResponse
    {
        return $this->postJson('/api/v1/store/purchases', array_filter(['store' => $store, 'product_id' => $product, 'reference' => $reference, 'target' => $target]));
    }

    public function test_an_account_gets_its_token_and_the_products_of_its_role(): void
    {
        $this->seeker();

        $first = $this->getJson('/api/v1/store/products')->assertOk();
        $products = array_column($first->json('data.products'), 'product_id');
        $this->assertTrue(Str::isUuid($first->json('data.account_token')));
        $this->assertSame($first->json('data.account_token'), $this->getJson('/api/v1/store/products')->json('data.account_token'), 'one token per account');
        $this->assertContains(self::SEEKER_PLAN, $products);
        $this->assertNotContains('com.zon.app.broker.month', $products, "another role's plan");
        // the app opens its simulated sheet only for a store the server itself says is simulated
        $this->assertSame(['apple', 'google'], $first->json('data.simulated'));
        $this->apiContract('store_products', $first);
    }

    public function test_a_store_subscription_becomes_a_plan_period_once_up_to_the_stores_expiry(): void
    {
        $seeker = $this->seeker();
        $reference = "sim:{$seeker->storeToken()}:abc123";

        $this->apiContract('subscription_free', $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.plan.subscribed', false));
        $this->apiContract('store_check_plan', $this->postJson('/api/v1/store/check', ['product_id' => self::SEEKER_PLAN])->assertOk());

        $bought = $this->buy('apple', self::SEEKER_PLAN, $reference)->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.provider', 'apple')->assertJsonPath('data.amount', '199.00');
        $this->apiContract('store_purchase_plan', $bought);
        $payment = $bought->json('data.payment_id');
        $this->assertSame($payment, $this->buy('apple', self::SEEKER_PLAN, $reference)->assertOk()->json('data.payment_id'), 'sent twice, the same payment');

        $this->assertSame([1, 1], [Payment::query()->count(), Subscription::query()->count()]);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, Subscription::query()->sole()->ends_at->timestamp, 5);
        $this->apiContract('subscription', $this->getJson('/api/v1/subscription')->assertJsonPath('data.plan.code', 'seeker')->assertJsonPath('data.plan.subscribed', true));
    }

    public function test_the_store_must_vouch_for_the_purchase_and_for_the_account(): void
    {
        $seeker = $this->seeker();
        $mine = $seeker->storeToken();
        $refused = fn (string $reference, string $reason) => $this->buy('google', self::SEEKER_PLAN, $reference)
            ->assertStatus(422)->assertJsonPath('error.code', 'PURCHASE_NOT_VERIFIED')->assertJsonPath('error.details.reason', $reason);

        $this->apiContract('store_purchase_refused', $refused('a-token-google-never-issued', 'unknown'));
        $refused('sim:'.Str::uuid().':abc123', 'other_account');
        $refused("sim:{$mine}:abc124:pending", 'pending');
        $refused("sim:{$mine}:abc125:revoked", 'refunded');
        $refused("sim:{$mine}:abc126:test", 'test_purchase');
        $this->buy('google', 'com.zon.app.unknown', "sim:{$mine}:abc127")->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(0, Payment::query()->count());

        $this->buy('google', self::SEEKER_PLAN, "sim:{$mine}:abc128")->assertOk();
        $other = $this->seeker();
        $this->buy('google', self::SEEKER_PLAN, "sim:{$mine}:abc128")->assertStatus(422)->assertJsonPath('error.details.reason', 'other_account');
        $this->assertFalse($other->fresh()->subscriptions()->exists());

        $tester = $this->seeker(tester: true);                        // a review account: the store's sandbox counts
        $this->buy('apple', self::SEEKER_PLAN, "sim:{$tester->storeToken()}:abc129:test")->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_a_boost_lands_on_the_named_listing_or_stays_unapplied_for_the_store_to_refund(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $target = ['type' => 'listing', 'id' => $listing->id];

        $this->postJson('/api/v1/store/check', ['product_id' => 'com.zon.app.featured', 'target' => $target])->assertOk()->assertJsonPath('data.account_token', $broker->storeToken());
        $this->apiContract('store_purchase_boost', $this->buy('google', 'com.zon.app.featured', "sim:{$broker->storeToken()}:feat01", $target)->assertOk()->assertJsonPath('data.status', 'paid'));
        $this->assertTrue($listing->fresh()->isFeatured());

        $this->apiContract('store_check_already_active', $this->postJson('/api/v1/store/check', ['product_id' => 'com.zon.app.featured', 'target' => $target])
            ->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ACTIVE'));
        $this->apiContract('store_purchase_unapplied', $this->buy('google', 'com.zon.app.featured', "sim:{$broker->storeToken()}:feat02", $target)->assertOk()     // the store charged anyway
            ->assertJsonPath('data.status', 'unapplied')->assertJsonPath('data.failure_reason', 'already_active'));

        $this->expectException(InvalidArgumentException::class);
        app(RefundPayment::class)(Payment::query()->where('status', 'unapplied')->sole(), 'من اللوحة');   // the store refunds its own sales
    }

    public function test_an_unapplied_purchase_is_never_acknowledged_so_the_store_refunds_it(): void
    {
        // R-01, review of 21 Nov: it was settled with the store like a paid one, so Google kept the charge
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $target = ['type' => 'listing', 'id' => $listing->id];
        Queue::fake([SettleStorePurchase::class]);

        $this->buy('google', 'com.zon.app.featured', "sim:{$broker->storeToken()}:feat11:unsettled", $target)->assertOk()->assertJsonPath('data.status', 'paid');
        Queue::assertPushed(SettleStorePurchase::class, 1);
        $this->buy('google', 'com.zon.app.featured', "sim:{$broker->storeToken()}:feat12:unsettled", $target)->assertOk()->assertJsonPath('data.status', 'unapplied');
        $this->buy('google', 'com.zon.app.featured', "sim:{$broker->storeToken()}:feat12:unsettled", $target)->assertOk()->assertJsonPath('data.status', 'unapplied');
        Queue::assertPushed(SettleStorePurchase::class, 1);
    }

    public function test_a_developers_project_is_featured_through_the_store_and_takes_no_free_place(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        Settings::put(Setting::ProjectFeaturedFree, 0);
        $project = Project::factory()->published()->create(['developer_id' => $developer->id]);
        $target = ['type' => 'project', 'id' => $project->id];

        $this->postJson('/api/v1/store/check', ['product_id' => 'com.zon.app.featured', 'target' => $target])->assertOk();
        $this->buy('apple', 'com.zon.app.featured', "sim:{$developer->storeToken()}:proj01", $target)->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertTrue($project->fresh()->featured_until->isFuture());
        $this->assertSame(BoostVia::Payment, BoostUsage::query()->where('target_id', $project->id)->sole()->via);
        $other = Project::factory()->published()->create(['developer_id' => Customer::factory()->developer()->create()->id]);
        $this->postJson('/api/v1/store/check', ['product_id' => 'com.zon.app.featured', 'target' => ['type' => 'project', 'id' => $other->id]])->assertNotFound();
    }

    public function test_renewals_and_refunds_arrive_as_store_notices_and_are_read_back(): void
    {
        $seeker = $this->seeker();
        $chain = "sim:{$seeker->storeToken()}:renew1";
        $this->buy('apple', self::SEEKER_PLAN, $chain)->assertOk();
        $notice = fn (array $sim, string $key = 'test-notices-key') => $this->postJson("/api/v1/store/notices/apple?key={$key}", ['simulation' => ['chain' => $chain] + $sim]);

        $notice(['reference' => "{$chain}:renewal-1"])->assertOk()->assertJsonPath('received', true);
        $notice(['reference' => "{$chain}:renewal-1"])->assertOk();          // the store sends again: still one renewal
        $periods = Subscription::query()->orderBy('id')->get();
        $this->assertSame([2, 2], [Payment::query()->count(), $periods->count()]);
        $this->assertTrue($periods[1]->starts_at->equalTo($periods[0]->ends_at), 'the renewal follows the first period');

        $notice(['reference' => "{$chain}:renewal-1:revoked"])->assertOk();  // refunded by the store
        $this->assertSame(['paid', 'refunded'], Payment::query()->orderBy('id')->pluck('status')->map->value->all());
        $this->assertNotNull($periods[1]->fresh()->cancelled_at);

        $notice(['reference' => 'sim:'.Str::uuid().':strange'])->assertOk();  // a purchase we never saw: nothing changes
        $notice(['reference' => "{$chain}:renewal-2"], 'wrong-key')->assertForbidden();
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_a_charge_whose_expiry_google_moves_later_keeps_the_plan_with_no_second_payment(): void
    {
        $seeker = $this->seeker();
        $chain = "sim:{$seeker->storeToken()}:grace1";
        $this->buy('google', self::SEEKER_PLAN, $chain)->assertOk();
        $period = Subscription::query()->sole();
        $end = $period->ends_at->toImmutable();
        $this->travel(28)->days();
        (new RemindSubscriptionRenewals)->handle();
        $this->assertSame(0, $seeker->notifications()->where('data->key', 'like', 'renewal:%')->count(), 'the store renews its own plans: no «it ends» reminder');

        $this->travel(2)->days();                     // the renewal's charge fails; Google retries it for 3 days (grace)
        $this->postJson('/api/v1/store/notices/google?key=test-notices-key', ['simulation' => ['chain' => $chain, 'reference' => "{$chain}:grace-3"]])->assertOk();

        $this->assertTrue($period->fresh()->ends_at->equalTo($end->addDays(3)));
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.plan.subscribed', true);
        $this->buy('google', self::SEEKER_PLAN, $chain)->assertOk();              // sent again as the store has it now
        $this->assertTrue($period->fresh()->ends_at->equalTo($end->addDays(3)), 'a later read never moves it back');
        $this->assertSame([1, 1], [Payment::query()->count(), Subscription::query()->count()]);
    }

    public function test_a_refund_google_reports_ends_the_boost_it_paid_for(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $chain = "sim:{$broker->storeToken()}:void01";
        $this->buy('google', 'com.zon.app.urgent.listing', $chain, ['type' => 'listing', 'id' => $listing->id])->assertOk();
        $this->assertTrue($listing->fresh()->isUrgent());

        $this->postJson('/api/v1/store/notices/google?key=test-notices-key', ['simulation' => ['chain' => $chain, 'reference' => $chain, 'voided' => 'sim-void01']])->assertOk();

        $this->assertSame('refunded', Payment::query()->sole()->status->value);
        $this->assertFalse($listing->fresh()->isUrgent());
    }

    public function test_a_google_purchase_gets_our_invoice_only_while_we_are_vat_registered(): void
    {
        $seeker = $this->seeker();
        Settings::put(Setting::GooglePlayVatByZon, true);
        $this->buy('google', self::SEEKER_PLAN, "sim:{$seeker->storeToken()}:vat001")->assertOk();
        $this->assertSame(0, Invoice::query()->count(), 'not registered: Google remits the tax, whatever that setting says');

        Settings::put(Setting::VatRegistered, true);
        Settings::put(Setting::VatNumber, '300000000000003');
        $this->buy('google', self::SEEKER_PLAN, "sim:{$seeker->storeToken()}:vat002")->assertOk();
        $this->assertNotNull(Invoice::query()->sole()->qr_tlv);
    }

    public function test_without_a_configured_key_no_notice_is_taken(): void
    {
        config(['services.store_notices_key' => '']);

        $this->postJson('/api/v1/store/notices/apple?key=', ['signedPayload' => 'x.y.z'])->assertForbidden();
    }
}
