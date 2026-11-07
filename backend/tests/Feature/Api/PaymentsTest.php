<?php

namespace Tests\Feature\Api;

use App\Actions\Admin\GrantSubscription;
use App\Actions\Admin\RefundPayment;
use App\Enums\ContractStatus;
use App\Enums\IndexEntity;
use App\Enums\OwnerCaseStage;
use App\Integrations\Payments\MoyasarGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Jobs\Scheduled\RemindSubscriptionRenewals;
use App\Models\AdLicense;
use App\Models\BoostUsage;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\Package;
use App\Models\Payment;
use App\Models\SearchRequest;
use App\Models\User;
use ArrayObject;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Money edge cases: a retry after a declined card, a target deleted before the money arrived, a second payment
 * for something already paid, periods bought ahead, and refunds from the panel or the provider. Every paid amount
 * is either applied once or kept «unapplied» for a refund, and a refund ends what it bought — nothing else.
 */
class PaymentsTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private function checkout(array $body): string
    {
        return $this->postJson('/api/v1/checkout', $body)->assertCreated()->json('data.payment_id');
    }

    /** @param array<string, mixed> $body */
    private function pay(array $body): string
    {
        $payment = $this->checkout($body);
        $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => "fake_{$payment}"])->assertJsonPath('data.status', 'paid');

        return $payment;
    }

    private function seeker(): Customer
    {
        $seeker = $this->signIn(Customer::factory()->seeker()->create());
        BuyerPreference::factory()->for($seeker)->create();

        return $seeker;
    }

    public function test_periods_of_the_same_plan_follow_each_other_however_far_ahead_they_are_bought(): void
    {
        $seeker = $this->seeker();
        foreach (range(1, 3) as $month) {
            $this->pay(['purpose' => 'subscription', 'package' => 'seeker']);       // renewed ahead, twice
        }
        $this->actingAs(User::factory()->superadmin()->create(), 'web');
        app(GrantSubscription::class)($seeker, Package::query()->where('code', 'seeker')->sole(), 7, 'تعويض');

        $periods = $seeker->subscriptions()->orderBy('id')->get();
        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($periods[$i]->starts_at->equalTo($periods[$i - 1]->ends_at), "period {$i} follows the one before it");
        }
        $this->assertEqualsWithDelta(now()->addDays(3 * 30 + 7)->timestamp, $periods[3]->ends_at->timestamp, 5);
    }

    public function test_the_renewal_reminder_is_only_for_a_plan_that_really_ends(): void
    {
        $seeker = $this->seeker();
        $this->pay(['purpose' => 'subscription', 'package' => 'seeker']);
        $this->travel(28)->days();
        $this->pay(['purpose' => 'subscription', 'package' => 'seeker']);            // renewed: the plan goes on
        $reminders = fn () => $seeker->notifications()->where('data->key', 'like', 'renewal:%')->count();

        (new RemindSubscriptionRenewals)->handle();
        $this->assertSame(0, $reminders());

        $this->travel(30)->days();                                                   // two days before the second ends
        (new RemindSubscriptionRenewals)->handle();
        $this->assertSame(1, $reminders());
    }

    public function test_refunding_an_old_boost_leaves_the_newer_one_running(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $first = $this->pay(['purpose' => 'featured', 'target' => ['type' => 'listing', 'id' => $listing->id]]);
        $this->travel(8)->days();                                                    // it ran its 7 days
        $this->pay(['purpose' => 'featured', 'target' => ['type' => 'listing', 'id' => $listing->id]]);
        $ended = BoostUsage::query()->oldest('id')->firstOrFail()->active_until;
        $this->actingAs(User::factory()->superadmin()->create(), 'web');

        app(RefundPayment::class)(Payment::query()->where('public_id', $first)->sole(), 'دفعة بالخطأ');

        $this->assertTrue($listing->fresh()->isFeatured(), 'the newer boost runs on');
        $this->assertTrue(BoostUsage::query()->oldest('id')->firstOrFail()->active_until?->equalTo($ended), 'the old one keeps its real end');
    }

    public function test_refunding_a_running_urgent_request_takes_it_off_the_index_too(): void
    {
        $seeker = $this->seeker();
        $request = SearchRequest::factory()->create(['customer_id' => $seeker->id]);
        $paid = $this->pay(['purpose' => 'urgent_request', 'target' => ['type' => 'request', 'id' => $request->id]]);
        $this->assertTrue($request->fresh()->urgent);
        $this->actingAs(User::factory()->superadmin()->create(), 'web');

        app(RefundPayment::class)(Payment::query()->where('public_id', $paid)->sole(), 'طلب العميل');

        $this->assertFalse($request->fresh()->urgent);
        $indexed = IndexEvent::query()->where('entity', IndexEntity::Request)->where('entity_id', $request->id)->latest('version')->firstOrFail();
        $this->assertFalse($indexed->payload['urgent']);
    }

    public function test_a_full_refund_made_at_moyasar_ends_the_plan_once_and_a_partial_one_does_not(): void
    {
        $seeker = $this->seeker();
        $payment = Payment::query()->where('public_id', $this->pay(['purpose' => 'subscription', 'package' => 'seeker']))->sole();
        $payment->forceFill(['provider' => 'moyasar', 'provider_ref' => 'pay-1'])->save();       // as if paid through Moyasar
        $this->app->instance(PaymentGateway::class, new MoyasarGateway('sk_test', 'pk_test', 'wh-secret'));
        $moyasar = new ArrayObject(['refunded' => 0]);                           // what Moyasar's dashboard has given back
        Http::fake(['api.moyasar.com/v1/payments/pay-1' => fn () => Http::response(['id' => 'pay-1', 'status' => $moyasar['refunded'] > 0 ? 'refunded' : 'paid',
            'amount' => $payment->amount_halalas, 'refunded' => $moyasar['refunded'], 'currency' => 'SAR', 'metadata' => ['payment_id' => $payment->public_id]])]);
        $notice = fn () => $this->postJson('/api/v1/payments/webhook/moyasar', ['type' => 'payment_refunded', 'secret_token' => 'wh-secret', 'data' => ['id' => 'pay-1']])
            ->assertOk()->assertJsonPath('received', true);

        $moyasar['refunded'] = 5000;                                                  // part of it: a gesture
        $notice();
        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertNull($seeker->subscriptions()->sole()->cancelled_at);

        $moyasar['refunded'] = $payment->amount_halalas;                              // then all of it
        $notice();
        $notice();                                                                    // Moyasar sends it again
        $this->assertSame('refunded', $payment->fresh()->status->value);
        $this->assertNotNull($seeker->subscriptions()->sole()->cancelled_at);
        $this->assertSame(1, Activity::query()->where('event', 'provider_refund')->count());
    }

    public function test_a_declined_attempt_does_not_hide_a_later_successful_retry(): void
    {
        $seeker = $this->signIn(Customer::factory()->seeker()->create());
        BuyerPreference::factory()->for($seeker)->create();                   // a seeker enters with a wish
        $payment = $this->checkout(['purpose' => 'subscription', 'package' => 'seeker']);

        $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => "fake_{$payment}_fail"])->assertJsonPath('data.status', 'failed');
        $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => "fake_{$payment}"])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->getJson('/api/v1/subscription')->assertJsonPath('data.plan.code', 'seeker')->assertJsonPath('data.plan.subscribed', true);
        $this->assertSame(1, $seeker->subscriptions()->count());
    }

    public function test_money_for_a_listing_deleted_before_payment_waits_for_a_refund(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->create(['customer_id' => $broker->id]);
        $payment = $this->checkout(['purpose' => 'featured', 'target' => ['type' => 'listing', 'id' => $listing->id]]);
        $this->deleteJson("/api/v1/listings/{$listing->id}")->assertOk();

        $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => "fake_{$payment}"])->assertOk()->assertJsonPath('data.status', 'unapplied');
    }

    public function test_a_second_fee_for_the_same_owner_case_issues_no_second_licence(): void
    {
        $owner = $this->signIn(Customer::factory()->owner()->create());
        $case = OwnerCase::query()->create(['customer_id' => $owner->id, 'deed_number' => '310112233445', 'stage' => OwnerCaseStage::Fee,
            'contract_status' => ContractStatus::Approved, 'contract_ref' => 'SIM-1', 'commission_percent' => '0.50']);
        $first = $this->checkout(['purpose' => 'ad_licence', 'target' => ['type' => 'owner_case', 'id' => $case->id]]);
        $this->travel(31)->minutes();                                   // the app crashed; a new checkout makes a second payment
        $second = $this->checkout(['purpose' => 'ad_licence', 'target' => ['type' => 'owner_case', 'id' => $case->id]]);
        $this->assertNotSame($first, $second);

        $this->postJson("/api/v1/payments/{$first}/confirm", ['reference' => "fake_{$first}"])->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/v1/payments/{$second}/confirm", ['reference' => "fake_{$second}"])->assertJsonPath('data.status', 'unapplied');

        $this->assertSame(1, AdLicense::query()->count());
        $this->assertSame([OwnerCaseStage::Listing, Payment::query()->where('public_id', $first)->value('id')], [$case->fresh()->stage, $case->fresh()->fee_payment_id]);
    }
}
