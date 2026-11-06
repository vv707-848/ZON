<?php

namespace Tests\Feature\Api;

use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\Package;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Services\Billing\Entitlements;
use Tests\TestCase;

/** Requests (normal and urgent), the board and who may see whom, the area brokers, wishes. */
class RequestsTest extends TestCase
{
    private function subscribe(Customer $customer, string $code): void
    {
        Subscription::query()->create(['customer_id' => $customer->id, 'package_id' => Package::query()->where('code', $code)->value('id'),
            'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(array $extra = []): array
    {
        return $extra + ['purpose' => 'sale', 'city' => 'الرياض', 'neighborhoods' => ['النرجس', 'الملقا'], 'property_types' => ['villa'],
            'budget_min' => '1500000', 'budget_max' => '2200000', 'description' => 'فيلا بمجلس مستقل'];
    }

    public function test_a_request_is_saved_indexed_and_reaches_the_area_brokers(): void
    {
        $broker = Customer::factory()->broker()->create(['city' => 'الرياض']);
        $elsewhere = Customer::factory()->broker()->create(['city' => 'جدة']);
        $owner = $this->signIn(Customer::factory()->owner()->create());

        $id = $this->postJson('/api/v1/requests', $this->form())->assertCreated()->assertJsonPath('data.urgent', false)->json('data.id');

        $this->assertSame('create', IndexEvent::query()->where('entity', 'request')->sole()->action->value);
        $this->assertSame(1, $broker->notifications()->count());
        $this->assertSame(0, $elsewhere->notifications()->count());
        $this->getJson('/api/v1/requests/mine')->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/v1/requests', $this->form(['budget_min' => 3000000]))->assertStatus(422)->assertJsonPath('error.details.fields.budget_max.0', 'يجب ألا يقل عن أقل ميزانية.');
    }

    public function test_urgent_comes_from_the_balance_or_asks_for_payment_without_losing_the_request(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());

        $this->postJson('/api/v1/requests', $this->form(['urgent' => true]))->assertCreated()
            ->assertJsonPath('data.urgent', false)->assertJsonPath('meta.urgent.status', 'payment_required')->assertJsonPath('meta.urgent.price', '50.00');

        $this->subscribe($broker, 'broker-year');
        $this->postJson('/api/v1/requests', $this->form(['urgent' => true]))->assertCreated()
            ->assertJsonPath('data.urgent', true)->assertJsonPath('meta.urgent.status', 'applied');
    }

    public function test_who_posted_is_hidden_from_guests_and_unsubscribed_brokers(): void
    {
        $request = SearchRequest::factory()->create();

        $this->getJson("/api/v1/requests/{$request->id}")->assertOk()->assertJsonPath('data.author', null)->assertJsonPath('data.author_role', 'seeker');

        $broker = $this->signIn(Customer::factory()->broker()->create());
        $this->getJson('/api/v1/requests/area')->assertJsonPath('meta.access', 'none')->assertJsonPath('data.0.author', null);
        $this->postJson("/api/v1/requests/{$request->id}/to-client")->assertStatus(402);

        $this->subscribe($broker, 'broker-month');
        Entitlements::forget($broker);
        $this->getJson('/api/v1/requests/area')->assertJsonPath('meta.access', 'full')->assertJsonPath('data.0.author.username', $request->customer->username);
        $this->postJson("/api/v1/requests/{$request->id}/to-client")->assertCreated();
        $client = CrmClient::query()->sole();
        $this->assertSame([$broker->id, null, ['النرجس', 'الملقا']], [$client->broker_id, $client->phone, $client->neighborhoods], 'no phone copied');
    }

    public function test_a_seeker_keeps_one_wish_free_and_more_with_a_plan(): void
    {
        $seeker = $this->signIn(Customer::factory()->seeker()->create());
        $wish = ['city' => 'الرياض', 'neighborhoods' => ['النرجس'], 'budget_max' => '900,000', 'notes' => 'قرب مدرسة'];

        $this->postJson('/api/v1/preferences', $wish)->assertCreated()->assertJsonPath('data.budget_max', 900000)->assertJsonPath('data.property_types', []);
        $this->getJson('/api/v1/favorites')->assertOk();   // the wish opens the app
        $this->postJson('/api/v1/preferences', $wish)->assertStatus(409)->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED');

        $this->subscribe($seeker, 'seeker');
        Entitlements::forget($seeker);
        $this->postJson('/api/v1/preferences', $wish)->assertCreated();
    }
}
