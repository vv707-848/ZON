<?php

namespace Tests\Feature\Api;

use App\Enums\ContractStatus;
use App\Enums\OwnerCaseStage;
use App\Models\AdLicense;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\Payment;
use Tests\TestCase;

/**
 * Money edge cases: a retry after a declined card, a target deleted before the money arrived, and a second
 * payment for something already paid. Every paid amount is either applied once or kept «unapplied» for a refund.
 */
class PaymentsTest extends TestCase
{
    private function checkout(array $body): string
    {
        return $this->postJson('/api/v1/checkout', $body)->assertCreated()->json('data.payment_id');
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
