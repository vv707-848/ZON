<?php

namespace Tests\Feature\Api;

use App\Enums\Purpose;
use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\Package;
use App\Models\SearchRequest;
use App\Models\Subscription;
use Tests\TestCase;

/**
 * «الطلبات» as the app shows it (requests_screen.dart, my_requests_view.dart): the board in its four orders, a request
 * edited, made urgent and closed by its author only. The board, a request and «طلباتي» are part of the app's recorded
 * contract (docs/contracts/fixtures/api).
 */
class RequestBoardTest extends TestCase
{
    /** @return array{old: SearchRequest, cheap: SearchRequest, urgent: SearchRequest, rent: SearchRequest, unpriced: SearchRequest} */
    private function board(): array
    {
        $old = SearchRequest::factory()->create(['budget_min' => 900000, 'budget_max' => 1200000]);
        $cheap = SearchRequest::factory()->create(['customer_id' => Customer::factory()->broker(), 'budget_min' => 400000, 'budget_max' => 600000, 'property_types' => ['apartment']]);
        $urgent = SearchRequest::factory()->urgent()->create(['customer_id' => Customer::factory()->owner(), 'budget_min' => 2000000, 'budget_max' => 2500000]);
        $rent = SearchRequest::factory()->create(['purpose' => Purpose::Rent, 'neighborhoods' => ['العليا'], 'budget_min' => null, 'budget_max' => 80000]);
        $unpriced = SearchRequest::factory()->create(['budget_min' => null, 'budget_max' => null, 'description' => 'أرض تجارية على شارعين']);
        SearchRequest::factory()->create(['status' => RequestStatus::Closed]);

        return compact('old', 'cheap', 'urgent', 'rent', 'unpriced');
    }

    /** @return list<int> */
    private function ids(string $query = ''): array
    {
        return array_column($this->getJson("/api/v1/requests{$query}")->assertOk()->json('data'), 'id');
    }

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
        return $extra + ['purpose' => 'sale', 'city' => 'الرياض', 'neighborhoods' => ['الملقا'], 'property_types' => ['apartment'],
            'budget_min' => 700000, 'budget_max' => 950000, 'rooms_min' => 3, 'description' => 'شقة عوائل قرب المدارس'];
    }

    public function test_the_board_orders_as_the_app_does(): void
    {
        $b = $this->board();
        $this->signIn();

        $this->assertSame([$b['urgent']->id, $b['unpriced']->id, $b['rent']->id, $b['cheap']->id, $b['old']->id], $this->ids(), 'newest, the urgent first');
        $this->assertSame([$b['urgent']->id, $b['old']->id, $b['cheap']->id, $b['rent']->id, $b['unpriced']->id], $this->ids('?sort=old'));
        $this->assertSame([$b['urgent']->id], $this->ids('?sort=urgent'));
        $this->assertSame([$b['rent']->id, $b['cheap']->id, $b['old']->id, $b['urgent']->id, $b['unpriced']->id], $this->ids('?sort=cheap'), 'by the lowest budget given; none last');
        $this->assertSame([$b['rent']->id], $this->ids('?purpose=rent&neighborhood=العليا'));
        $this->assertSame([$b['cheap']->id], $this->ids('?role=broker&property_type=apartment'));
        $this->getJson('/api/v1/requests?sort=random')->assertStatus(422);

        $page = $this->getJson('/api/v1/requests?per_page=5')->assertOk();
        $this->assertSame(['current' => 1, 'per_page' => 5, 'total' => 5, 'last' => 1], $page->json('meta.page'));
        $this->apiContract('requests', $page);
        $this->apiContract('request', $this->getJson("/api/v1/requests/{$b['urgent']->id}")->assertOk());
    }

    public function test_its_author_edits_it_and_nobody_else(): void
    {
        $author = Customer::factory()->owner()->create();
        $request = SearchRequest::factory()->create(['customer_id' => $author->id, 'rooms_max' => 6]);

        $this->signIn(Customer::factory()->broker()->create());
        $this->putJson("/api/v1/requests/{$request->id}", $this->form())->assertNotFound();

        $this->signIn($author);
        $this->putJson("/api/v1/requests/{$request->id}", $this->form(['urgent' => true]))->assertOk()
            ->assertJsonPath('data.neighborhoods', ['الملقا'])->assertJsonPath('data.property_types', ['apartment'])
            ->assertJsonPath('data.rooms_max', null)                // a range emptied in the form is emptied here
            ->assertJsonPath('data.urgent', false);                 // «عاجل» is bought, not edited
        $event = IndexEvent::query()->where('entity', 'request')->where('entity_id', $request->id)->latest('id')->first();
        $this->assertStringContainsString('شقة عوائل', (string) json_encode($event?->payload, JSON_UNESCAPED_UNICODE), 'the search index has the edit');
        $this->putJson("/api/v1/requests/{$request->id}", $this->form(['budget_min' => 990000]))->assertStatus(422);

        $this->postJson("/api/v1/requests/{$request->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->putJson("/api/v1/requests/{$request->id}", $this->form())->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
        $this->assertNotContains($request->id, $this->ids(), 'a closed request leaves the board');
        $mine = $this->getJson('/api/v1/requests/mine')->assertOk()->assertJsonPath('data.0.status', 'closed');
        $this->apiContract('requests_mine', $mine);
    }

    public function test_made_urgent_from_the_balance_or_asks_for_the_price(): void
    {
        $author = $this->signIn(Customer::factory()->broker()->create());
        $request = SearchRequest::factory()->create(['customer_id' => $author->id]);

        $this->postJson("/api/v1/requests/{$request->id}/urgent")->assertStatus(402)->assertJsonPath('error.code', 'PAYMENT_REQUIRED')
            ->assertJsonPath('error.details.price', '50.00')->assertJsonPath('error.details.purpose', 'urgent_request')
            ->assertJsonPath('error.details.target', ['type' => 'request', 'id' => $request->id]);
        $this->assertFalse($request->refresh()->urgent);

        $this->subscribe($author, 'broker-year');
        $this->postJson("/api/v1/requests/{$request->id}/urgent")->assertOk()->assertJsonPath('data.urgent', true);
        $this->postJson("/api/v1/requests/{$request->id}/urgent")->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ACTIVE');

        $this->signIn(Customer::factory()->broker()->create());
        $this->postJson("/api/v1/requests/{$request->id}/urgent")->assertNotFound();
    }

    public function test_a_new_request_and_the_brokers_area_are_part_of_the_contract(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create(['city' => 'الرياض']));
        $created = $this->postJson('/api/v1/requests', $this->form(['urgent' => true]))->assertCreated()
            ->assertJsonPath('meta.urgent.status', 'payment_required')->assertJsonPath('data.urgent', false);
        $this->apiContract('request_created', $created);

        SearchRequest::factory()->urgent()->create();
        $this->subscribe($broker, 'broker-month');
        $area = $this->getJson('/api/v1/requests/area')->assertOk()->assertJsonPath('meta.access', 'full')->assertJsonPath('data.0.can_add_to_clients', true);
        $this->assertNotContains($created->json('data.id'), array_column($area->json('data'), 'id'), 'his own are not his area\'s');
        $this->apiContract('requests_area', $area);
    }
}
