<?php

namespace Tests\Feature\Api;

use App\Enums\ContractStatus;
use App\Integrations\Rega\FakeRegaGateway;
use App\Integrations\Rega\RegaGateway;
use App\Models\CommissionDue;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\OwnerCase;
use App\Models\SaleReport;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «عرض المالك» as the app does it with a server (listing_form.dart, server_owner_cases.dart, server_payments.dart):
 * his cases, a new one by its deed, the contract's answer, the licence fee paid in the app, then his listing on
 * the licence of its case — once however often sent. Its answers are the app's recorded contract.
 */
class OwnerListingTest extends TestCase
{
    private function wording(): void
    {
        Http::fake(['agents.test/agents/*' => Http::response(['output' => ['desc_clean' => 'فيلا واسعة بحديقة خلفية في حي هادئ.', 'features' => []], 'degraded' => false,
            'usage' => ['tokens_in' => 300, 'tokens_out' => 80], 'latency_ms' => 900])]);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(int $case): array
    {
        return [
            'purpose' => 'sale', 'property_type' => 'villa', 'title' => 'فيلا في الملقا', 'city' => 'الرياض', 'neighborhood' => 'الملقا',
            'price' => 2000000, 'area_land' => 400, 'area_built' => 350, 'bedrooms' => 5, 'bathrooms' => 4, 'halls' => 2, 'age' => 3,
            'description' => 'فيلا واسعة بحديقة خلفية', 'owner_case_id' => $case,
        ];
    }

    public function test_his_case_from_its_deed_to_the_fee_paid_in_the_app_then_his_listing_once(): void
    {
        $this->wording();
        $this->signIn(Customer::factory()->owner()->create());
        $this->apiContract('owner_cases_none', $this->getJson('/api/v1/owner-cases')->assertOk()->assertJsonCount(0, 'data'));

        // the contract filed: its answer is asked of REGA on every look; here it is not yet approved
        $this->app->instance(RegaGateway::class, new class extends FakeRegaGateway
        {
            public function contractStatus(string $reference): ContractStatus
            {
                return ContractStatus::Submitted;
            }
        });
        $opened = $this->postJson('/api/v1/owner-cases', ['deed_number' => '310112233445'], ['Idempotency-Key' => 'own-case-0001'])->assertCreated()
            ->assertJsonPath('data.stage', 'contract')->assertJsonPath('data.next.action', 'approve_contract');
        $this->postJson('/api/v1/owner-cases', ['deed_number' => '310112233445'], ['Idempotency-Key' => 'own-case-0001'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, OwnerCase::query()->count(), 'sent twice, opened once');
        $this->apiContract('owner_case_opened', $opened);
        $case = (int) $opened->json('data.id');
        $this->apiContract('owner_case_waiting', $this->getJson("/api/v1/owner-cases/{$case}")->assertOk()->assertJsonPath('data.stage', 'contract'));

        $this->app->forgetInstance(RegaGateway::class);
        $this->app->bind(RegaGateway::class, FakeRegaGateway::class);   // approved
        $fee = $this->getJson("/api/v1/owner-cases/{$case}")->assertOk()->assertJsonPath('data.stage', 'fee')->assertJsonPath('data.next.action', 'pay_fee');
        $this->apiContract('owner_case_fee', $fee);
        $this->apiContract('owner_cases', $this->getJson('/api/v1/owner-cases')->assertOk()->assertJsonPath('data.0.id', $case));

        $checkout = $this->postJson('/api/v1/checkout', ['purpose' => 'ad_licence', 'target' => ['type' => 'owner_case', 'id' => $case]])->assertCreated()
            ->assertJsonPath('data.amount', $fee->json('data.next.price'));
        $this->apiContract('checkout_ad_licence', $checkout);
        $payment = $checkout->json('data.payment_id');
        $this->apiContract('payment_declined', $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => $checkout->json('data.client.decline_reference')])
            ->assertOk()->assertJsonPath('data.status', 'failed'));
        $this->getJson("/api/v1/owner-cases/{$case}")->assertJsonPath('data.stage', 'fee');

        $again = $this->postJson('/api/v1/checkout', ['purpose' => 'ad_licence', 'target' => ['type' => 'owner_case', 'id' => $case]])->assertCreated();
        $this->apiContract('payment_paid', $this->postJson("/api/v1/payments/{$again->json('data.payment_id')}/confirm", ['reference' => $again->json('data.client.reference')])
            ->assertOk()->assertJsonPath('data.status', 'paid'));
        $ready = $this->getJson("/api/v1/owner-cases/{$case}")->assertJsonPath('data.stage', 'listing')->assertJsonPath('data.next.owner_case_id', $case);
        $this->assertNotNull($ready->json('data.licence.number'));
        $this->apiContract('owner_case_listing', $ready);

        $created = $this->postJson('/api/v1/listings', $this->form($case), ['Idempotency-Key' => 'lst-owner-0001'])->assertCreated();
        $this->postJson('/api/v1/listings', $this->form($case), ['Idempotency-Key' => 'lst-owner-0001'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->postJson('/api/v1/listings', $this->form($case), ['Idempotency-Key' => 'lst-owner-0002'])->assertStatus(409)->assertJsonPath('error.code', 'LICENCE_REQUIRED');   // its licence is taken
        $this->assertSame(1, Listing::query()->count(), 'one listing per licence');
        $this->apiContract('owner_listing_created', $created);
        $this->getJson("/api/v1/owner-cases/{$case}")->assertJsonPath('data.stage', 'active')->assertJsonPath('data.listing_id', $created->json('data.id'));

        // sold at the price he says it sold for (asked when he marks it): the commission is reckoned on it
        $listing = $created->json('data.id');
        $this->postJson("/api/v1/listings/{$listing}/review", ['decision' => 'reject'])->assertOk();
        $this->postJson("/api/v1/listings/{$listing}/sold")->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['sale_price']]]]);
        $this->postJson("/api/v1/listings/{$listing}/sold", ['sale_price' => '1850000.505'])->assertStatus(422);   // not rounded away
        $this->assertSame(0, CommissionDue::query()->count(), 'nothing due before his price');
        $this->apiContract('owner_listing_sold', $this->postJson("/api/v1/listings/{$listing}/sold", ['sale_price' => 1850000])->assertOk()->assertJsonPath('data.status', 'sold'));
        $due = CommissionDue::query()->sole();
        $this->assertSame('1850000.00', (string) $due->sale_price);
        $this->assertSame((int) round(1850000 * 100 * (float) $ready->json('data.commission_percent') / 100), (int) $due->amount_halalas, 'the case\'s percent of his sale price');

        // R-06: «مباع» again (its reply lost, another device, a new key) changes nothing: one sale, one commission
        $this->postJson("/api/v1/listings/{$listing}/sold", ['sale_price' => 1850000])->assertOk()->assertJsonPath('data.status', 'sold');
        $this->postJson("/api/v1/listings/{$listing}/sold", ['sale_price' => 1900000], ['Idempotency-Key' => 'sold-0002-abcdef'])->assertOk();
        $this->assertSame(1, CommissionDue::query()->where('listing_id', $listing)->count(), 'the same sale again adds no commission');
        $this->assertSame('1850000.00', (string) CommissionDue::query()->sole()->sale_price, 'nor changes it');
        $this->assertSame(1, SaleReport::query()->where('subject_id', $listing)->count(), 'nor reports it twice');
    }

    public function test_a_refused_contract_says_so_and_another_owner_cannot_see_his_case(): void
    {
        $this->signIn(Customer::factory()->owner()->create());
        $this->app->instance(RegaGateway::class, new class extends FakeRegaGateway
        {
            public function contractStatus(string $reference): ContractStatus
            {
                return ContractStatus::Rejected;
            }
        });
        $case = $this->postJson('/api/v1/owner-cases', ['deed_number' => '310199887766'])->assertCreated()->json('data.id');
        $this->apiContract('owner_case_refused', $this->getJson("/api/v1/owner-cases/{$case}")->assertOk()
            ->assertJsonPath('data.stage', 'contract')->assertJsonPath('data.contract_status', 'rejected'));

        $this->postJson('/api/v1/owner-cases', ['deed_number' => '310199887766'])->assertCreated();   // filed again
        $this->postJson('/api/v1/owner-cases', ['deed_number' => '310199887766'])->assertStatus(409);  // once on its way

        $this->signIn(Customer::factory()->owner()->create());
        $this->getJson("/api/v1/owner-cases/{$case}")->assertNotFound();
        $this->getJson('/api/v1/owner-cases')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/checkout', ['purpose' => 'ad_licence', 'target' => ['type' => 'owner_case', 'id' => $case]])->assertStatus(404);
    }
}
