<?php

namespace Tests\Feature\Api;

use App\Models\AiRequest;
use App\Models\BuyerPreference;
use App\Models\ChatMessage;
use App\Models\ClientNote;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchMiss;
use App\Models\SearchRequest;
use App\Models\Subscription;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Every AI service through AiGateway: SQL candidates, masking, ids checked, quota, cache, fallback — a result is a record and its score. */
class AiTest extends TestCase
{
    /** @param array<string, mixed> $output */
    private function agent(array $output, int $status = 200, bool $degraded = false): void
    {
        Http::fake(['agents.test/agents/*' => Http::response($status === 200 ? ['output' => $output, 'degraded' => $degraded, 'usage' => ['tokens_in' => 900, 'tokens_out' => 120], 'latency_ms' => 2100] : ['detail' => 'down'], $status)]);
    }

    private function seeker(): Customer
    {
        $seeker = Customer::factory()->seeker()->create();
        BuyerPreference::factory()->for($seeker)->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'budget_max' => 900000, 'notes' => 'حوش واسع للتواصل 0551234567']);
        Subscription::query()->create(['customer_id' => $seeker->id, 'package_id' => Package::query()->where('code', 'seeker')->value('id'), 'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        return $this->signIn($seeker);
    }

    public function test_search_ranks_only_offered_ids_and_charges_on_a_full_answer(): void
    {
        $near = Listing::factory()->published()->create(['price' => 880000]);
        $far = Listing::factory()->published()->create(['price' => 2000000]);
        $this->agent(['results' => [   // as an older agents build answered: with words nobody shows
            ['id' => $far->id, 'score' => 0.9],
            ['id' => 999999, 'score' => 0.8],
            ['id' => $near->id, 'score' => 1.7, 'reason' => 'قريب من الميزانية'],
        ], 'query_understood' => 'شقة · النرجس']);
        $this->seeker();

        $response = $this->postJson('/api/v1/ai/search', ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'budget_max' => 900000, 'query' => 'فيها حوش، كلمني 0551234567'], ['X-Device-Id' => 'device-1'])
            ->assertOk()->assertJsonPath('meta.ai', ['status' => 'ok'])->assertHeader('X-AI-Limit', '30')->assertHeader('X-AI-Remaining', '29');

        $this->assertSame([$far->id, $near->id], array_column(array_column($response->json('data.results'), 'listing'), 'id'), 'the invented id is dropped');
        $this->assertSame([['results'], ['listing', 'score']], [array_keys($response->json('data')), array_keys($response->json('data.results.1'))], 'the record and its score, nothing written');
        $this->assertEquals(1, $response->json('data.results.1.score'), 'scores are kept within 0–1');
        Http::assertSent(fn (Sent $r) => str_ends_with($r->url(), '/agents/search_listings')
            && ! str_contains($r->body(), '0551234567') && in_array('فناء', $r['terms'], true)
            && $r['candidates'][0]['id'] === $near->id && $r->hasHeader('X-Request-Id'));
        $this->assertSame('ok', AiRequest::query()->sole()->status->value);
    }

    public function test_nothing_to_rank_is_a_recorded_miss_without_a_call_or_a_charge(): void
    {
        Http::fake();
        $this->seeker();

        $this->postJson('/api/v1/ai/search', ['city' => 'الرياض', 'neighborhood' => 'العليا', 'property_type' => 'villa'])
            ->assertOk()->assertJsonPath('data.results', [])->assertJsonPath('meta.ai.status', 'skipped')->assertHeader('X-AI-Remaining', '30');
        Http::assertNothingSent();
        $this->assertSame('العليا', SearchMiss::query()->sole()->neighborhood);
    }

    public function test_without_the_ai_the_sql_order_answers_and_nothing_is_charged(): void
    {
        Listing::factory()->published()->create(['price' => 2000000]);
        $closest = Listing::factory()->published()->create(['price' => 905000]);
        $this->agent([], 503);
        $this->seeker();

        $this->postJson('/api/v1/ai/search', ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'budget_max' => 900000])
            ->assertOk()->assertJsonPath('meta.ai', ['status' => 'fallback'])
            ->assertJsonPath('data.results.0.listing.id', $closest->id)->assertHeader('X-AI-Remaining', '30');
    }

    public function test_failed_answers_are_free_but_the_tries_are_not_unlimited(): void
    {
        Listing::factory()->published()->create();
        $this->agent([], 503);
        Package::query()->where('code', Package::SEEKER)->update(['ai_search_daily' => 2]);
        $this->seeker();
        $search = fn () => $this->postJson('/api/v1/ai/search', ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment']);

        foreach (range(1, 6) as $try) {                                  // 3 × the cap of 2, none of them charged
            $search()->assertOk()->assertJsonPath('meta.ai.status', 'fallback')->assertHeader('X-AI-Remaining', '2');
        }
        $search()->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_the_same_question_within_fifteen_minutes_is_answered_from_the_cache_for_free(): void
    {
        $listing = Listing::factory()->published()->create();
        $this->agent(['results' => [['id' => $listing->id, 'score' => 0.7]]]);
        $this->seeker();
        $form = ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment'];

        $this->postJson('/api/v1/ai/search', $form)->assertHeader('X-AI-Remaining', '29');
        $this->postJson('/api/v1/ai/search', $form)->assertJsonPath('meta.ai.status', 'cached')->assertHeader('X-AI-Remaining', '29');
        Http::assertSentCount(1);
    }

    public function test_quota_layers_and_upgrade_offers(): void
    {
        $listing = Listing::factory()->published()->create();
        $this->agent(['results' => [['id' => $listing->id, 'score' => 0.7]]]);
        $form = fn (int $i) => ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'query' => "طلب {$i}"];

        $free = Customer::factory()->seeker()->create();
        BuyerPreference::factory()->for($free)->create();
        $this->signIn($free);
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/ai/search', $form($i), ['X-Device-Id' => 'shared-phone'])->assertOk();
        }
        $this->postJson('/api/v1/ai/search', $form(4), ['X-Device-Id' => 'shared-phone'])->assertStatus(429)
            ->assertJsonPath('error.code', 'QUOTA_EXCEEDED')->assertJsonPath('error.details.upgrade.0.package', 'seeker');

        // A second free account on the same phone: the device layer allows 3 × 1.5 = 5 in total.
        $second = Customer::factory()->seeker()->create();
        BuyerPreference::factory()->for($second)->create();
        $this->signIn($second);
        $this->postJson('/api/v1/ai/search', $form(5), ['X-Device-Id' => 'shared-phone'])->assertOk();
        $this->postJson('/api/v1/ai/search', $form(6), ['X-Device-Id' => 'shared-phone'])->assertOk();
        $this->postJson('/api/v1/ai/search', $form(7), ['X-Device-Id' => 'shared-phone'])->assertStatus(429);

        $this->signIn(Customer::factory()->owner()->create());
        $this->postJson('/api/v1/ai/summaries', ['scope' => 'tasks'])->assertStatus(402)->assertJsonPath('error.code', 'PAYMENT_REQUIRED');
    }

    public function test_matching_reads_the_wish_and_groups_unit_models_into_projects(): void
    {
        $listing = Listing::factory()->published()->create(['price' => 850000]);
        $project = Project::factory()->published()->create(['neighborhood' => 'النرجس']);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id, 'property_type' => 'apartment', 'price' => 870000]);
        $this->agent(['matched' => [['id' => $listing->id, 'score' => 0.8]], 'broaden' => [], 'units' => [['id' => $unit->id, 'score' => 0.6]]]);
        $this->seeker();

        $this->postJson('/api/v1/ai/matching')->assertOk()
            ->assertJsonPath('data.matched.0.listing.id', $listing->id)
            ->assertJsonPath('data.projects.0.project.id', $project->id)
            ->assertJsonPath('data.projects.0.units.0.unit.id', $unit->id)
            ->assertJsonPath('data.suggest_request', false);
        Http::assertSent(fn (Sent $r) => $r['need']['neighborhood'] === 'النرجس' && $r['need']['budget_max'] == 900000 && ! str_contains($r['need']['free_text'], '0551234567'));
    }

    public function test_the_chat_answers_by_intent_and_its_guard_replaces_unsafe_answers(): void
    {
        $this->seeker();
        Http::fake(['agents.test/agents/chat' => Http::sequence()
            ->push(['output' => ['answer' => 'باقة الباحث 199 ريال شهرياً.', 'covered' => true, 'sources' => []], 'degraded' => false, 'usage' => [], 'latency_ms' => 900])
            ->push(['output' => ['answer' => 'كلمنا على 0551234567', 'covered' => true, 'sources' => [['doc_id' => 1, 'title' => 'x']]], 'degraded' => false, 'usage' => [], 'latency_ms' => 900])
            ->push(['output' => ['answer' => 'الرسوم 75 ريال.', 'covered' => true, 'sources' => [['doc_id' => 1, 'title' => 'x']]], 'degraded' => false, 'usage' => [], 'latency_ms' => 900])]);

        $this->postJson('/api/v1/ai/chat', ['message' => 'وش رأيك بالمباراة؟'])->assertOk()->assertJsonPath('data.intent', 'out_of_scope')->assertJsonPath('meta.ai.status', 'skipped');
        $this->postJson('/api/v1/ai/chat', ['message' => 'كم سعر الباقة؟'])->assertOk()->assertJsonPath('data.answer', 'باقة الباحث 199 ريال شهرياً.')->assertJsonPath('data.action', '/account/plans');
        Http::assertSent(fn (Sent $r) => $r['intent'] === 'packages' && collect((array) $r['packages'])->contains('price', 199.0));
        $this->postJson('/api/v1/ai/chat', ['message' => 'كيف أطلع ترخيص فال؟'])->assertJsonPath('data.answer', 'لا أملك معلومة موثقة عن هذا. راجع الجهة الرسمية المختصة.');
        $this->postJson('/api/v1/ai/chat', ['message' => 'كم رسوم الترخيص؟'])->assertJsonPath('data.covered', false);

        $this->assertSame(['passed', 'passed', 'replaced', 'replaced'], ChatMessage::query()->orderBy('id')->pluck('guard_status')->all());
        $this->getJson('/api/v1/ai/chat')->assertJsonCount(4, 'data');
    }

    public function test_a_client_summary_is_masked_stored_and_reused_while_the_notes_are_unchanged(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        Subscription::query()->create(['customer_id' => $broker->id, 'package_id' => Package::query()->where('code', 'broker-month')->value('id'), 'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $client = CrmClient::query()->create(['broker_id' => $broker->id, 'name' => 'خالد المطيري', 'phone' => '+966550000001', 'city' => 'الرياض']);
        ClientNote::query()->create(['client_id' => $client->id, 'category' => 'cash', 'body' => 'خالد المطيري جاهز كاش ويريد النرجس']);
        $this->agent(['summary' => 'العميل جاهز للشراء نقداً.', 'signals' => ['كاش'], 'recommended_action' => 'اعرض عليه النرجس', 'confidence' => 'high']);

        $this->postJson('/api/v1/ai/summaries', ['scope' => 'client', 'id' => $client->id])->assertOk()->assertJsonPath('data.signals.0', 'كاش')->assertJsonPath('meta.ai.status', 'ok');
        $this->postJson('/api/v1/ai/summaries', ['scope' => 'client', 'id' => $client->id])->assertOk()->assertJsonPath('meta.ai.status', 'cached');
        Http::assertSentCount(1);
        Http::assertSent(fn (Sent $r) => ! str_contains($r->body(), 'خالد') && str_contains($r['notes'][0]['text'], 'العميل'));

        $other = CrmClient::query()->create(['broker_id' => Customer::factory()->broker()->create()->id, 'name' => 'آخر', 'city' => 'الرياض']);
        $this->postJson('/api/v1/ai/summaries', ['scope' => 'client', 'id' => $other->id])->assertNotFound();
    }

    public function test_a_developer_finds_seekers_and_an_invented_unit_becomes_none(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $project = Project::factory()->published()->create(['developer_id' => $developer->id, 'neighborhood' => 'النرجس']);
        $villa = ProjectUnit::factory()->create(['project_id' => $project->id, 'property_type' => 'villa']);
        $wish = BuyerPreference::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['villa'], 'notes' => 'قرب مدرسة']);
        $other = BuyerPreference::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['villa']]);
        $this->agent(['results' => [['id' => $wish->id, 'score' => 0.9, 'unit_id' => 424242], ['id' => $other->id, 'score' => 0.7, 'unit_id' => $villa->id]]]);

        $this->postJson("/api/v1/ai/matching/projects/{$project->id}/searchers")->assertOk()
            ->assertJsonPath('data.0.seeker.username', $wish->customer->username)->assertJsonPath('data.0.unit', null)
            ->assertJsonPath('data.1.unit.id', $villa->id);
        Http::assertSent(fn (Sent $r) => ! str_contains($r->body(), $wish->customer->name) && ! str_contains($r->body(), $wish->customer->phone));
    }

    public function test_a_broker_searches_requests_only_on_a_plan_that_includes_it(): void
    {
        $wanted = SearchRequest::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'budget_max' => 900000]);
        $this->agent(['results' => [['id' => $wanted->id, 'score' => 0.8]]]);
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $need = ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'budget_max' => 880000];

        $this->postJson('/api/v1/ai/search/requests', $need, ['X-Device-Id' => 'device-1'])->assertStatus(402)->assertJsonPath('error.code', 'PAYMENT_REQUIRED');
        Http::assertNothingSent();

        Subscription::query()->create(['customer_id' => $broker->id, 'package_id' => Package::query()->where('code', Package::BROKER_MONTH)->value('id'), 'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->postJson('/api/v1/ai/search/requests', $need, ['X-Device-Id' => 'device-1'])->assertOk()
            ->assertJsonPath('data.results.0.request.id', $wanted->id)->assertJsonPath('meta.ai.status', 'ok');
        Http::assertSent(fn (Sent $r) => str_ends_with($r->url(), '/agents/search_requests') && $r['candidates'][0]['id'] === $wanted->id
            && ! str_contains($r->body(), $wanted->customer->phone));
    }

    public function test_not_a_match_needs_its_reason(): void
    {
        $this->seeker();
        $this->postJson('/api/v1/ai/feedback', ['context' => 'matching', 'result_type' => 'listing', 'result_id' => 5, 'is_match' => false])->assertStatus(422);
        $this->postJson('/api/v1/ai/feedback', ['context' => 'matching', 'result_type' => 'listing', 'result_id' => 5, 'is_match' => false, 'reason' => 'بعيد عن المدارس'])->assertCreated();
    }
}
