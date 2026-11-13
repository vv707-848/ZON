<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\LegalPage;
use App\Models\Listing;
use Illuminate\Broadcasting\BroadcastManager;
use Tests\TestCase;

/**
 * What the app reads of the plans, its payments and invoices, its AI balance, a policy's page, and the live
 * channels: as the app will do it with a server, each refused to another account. Its answers are the app's
 * recorded contract.
 */
class AccountReadsTest extends TestCase
{
    public function test_the_plans_a_payment_and_its_invoice(): void
    {
        $this->apiContract('packages', $this->getJson('/api/v1/packages')->assertOk()->assertJsonStructure(['data' => ['plans' => [['code', 'price', 'purchasable']]]]));

        $broker = $this->signIn(Customer::factory()->broker()->create());
        $listing = Listing::factory()->published()->for($broker)->create();
        $checkout = $this->postJson('/api/v1/checkout', ['purpose' => 'featured', 'target' => ['type' => 'listing', 'id' => $listing->id]])->assertCreated();
        $payment = $checkout->json('data.payment_id');
        $this->getJson("/api/v1/payments/{$payment}")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => $checkout->json('data.client.reference')])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->apiContract('payment', $this->getJson("/api/v1/payments/{$payment}")->assertOk()->assertJsonPath('data.status', 'paid'));

        $invoices = $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->assertMatchesRegularExpression('/^ZON-\d{4}-\d{6}$/', (string) $invoices->json('data.0.number'));
        $this->apiContract('invoices', $invoices);
        $id = $invoices->json('data.0.id');
        $invoice = $this->getJson("/api/v1/invoices/{$id}")->assertOk()->assertJsonPath('data.total', $checkout->json('data.amount'));
        $this->assertEqualsWithDelta((float) $invoice->json('data.total'), (float) $invoice->json('data.subtotal') + (float) $invoice->json('data.vat'), 0.001, 'its total is its subtotal and VAT');
        $this->assertNotEmpty($invoice->json('data.lines'));
        $this->apiContract('invoice', $invoice);

        $this->signIn(Customer::factory()->broker()->create());
        $this->getJson("/api/v1/payments/{$payment}")->assertNotFound();
        $this->getJson("/api/v1/invoices/{$id}")->assertNotFound();
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_his_ai_balance_and_a_policy_page(): void
    {
        $this->signIn(Customer::factory()->broker()->create());
        $quota = $this->getJson('/api/v1/ai/quota')->assertOk();
        foreach (['search', 'matching', 'summary', 'chat'] as $use) {
            $row = $quota->json("data.uses.{$use}");
            $this->assertSame($row['limit'] - $row['used'], $row['remaining'], $use);
        }
        $this->assertNotNull($quota->json('data.resets_at'));
        $this->apiContract('ai_quota', $quota);

        LegalPage::query()->updateOrCreate(['slug' => 'privacy'], ['title' => 'سياسة الخصوصية', 'body' => 'نص السياسة', 'version' => '1', 'published_at' => null]);
        $this->getJson('/api/v1/legal/privacy')->assertNotFound();                        // not published yet
        LegalPage::query()->where('slug', 'privacy')->update(['version' => '2', 'published_at' => now()]);
        $this->apiContract('legal_page', $this->getJson('/api/v1/legal/privacy')->assertOk()->assertJsonPath('data.version', '2')->assertJsonPath('data.title', 'سياسة الخصوصية'));
        $this->getJson('/api/v1/legal/no-such-page')->assertNotFound();
    }

    public function test_the_live_channels_are_their_accounts_alone(): void
    {
        $broker = Customer::factory()->broker()->create();
        $listing = Listing::factory()->published()->for($broker)->create();
        $seeker = $this->signIn(Customer::factory()->broker()->create());
        $conversation = $this->postJson('/api/v1/conversations', ['to' => $broker->username, 'listing_id' => $listing->id, 'body' => 'هل العرض متاح؟'])
            ->assertCreated()->json('data.conversation_id');

        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app']);
        $manager = app(BroadcastManager::class);
        $manager->purge();
        require base_path('routes/channels.php');                                          // its rules, on the live driver

        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-customers.{$seeker->id}"])->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$conversation}"])->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-customers.{$broker->id}"])->assertForbidden();

        $this->signIn(Customer::factory()->broker()->create());
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$conversation}"])->assertForbidden();
    }
}
