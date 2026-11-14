<?php

namespace Tests\Chain;

use App\Enums\TextReview;
use App\Indexing\IndexSync;
use App\Jobs\CleanListingText;
use App\Models\AiRequest;
use App\Models\BuyerPreference;
use App\Models\ClientNote;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\KbDocument;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SearchRequest;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The AI chain end to end, nothing faked in between: this API → AiGateway → the agents service (a real process)
 * → the simulated AI node (a real process: the rule model and bag-of-words vectors, agents/zon_agents/sim).
 * Every result is known before it runs, because the node answers by documented rules — so each of the eight
 * functions is asked as the app asks it and compared with the one right answer: the order, the scores, the
 * quota, what was charged, and what reached the node (never a name or a number).
 *
 * It is not a measure of a model: `meta.ai.simulated` says so on every answer. The answers recorded here
 * (docs/contracts/fixtures/api/ai_*.json) are what the app's tests replay.
 *
 * Runs with the two processes up (CI: «Chain»; locally docs/model-lab.md «المحاكاة»):
 *   ZON_AI_CHAIN=1 vendor/bin/phpunit --testsuite Chain
 */
class AiChainTest extends TestCase
{
    private const PHONE = '0551234567';

    private string $sim;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ZON_AI_CHAIN') !== '1') {
            $this->markTestSkipped('The chain needs the agents service and the simulated node running (ZON_AI_CHAIN=1).');
        }
        $this->sim = rtrim(getenv('ZON_CHAIN_SIM_URL') ?: 'http://127.0.0.1:30000', '/');
        config(['zon.agents.url' => getenv('ZON_CHAIN_AGENTS_URL') ?: 'http://127.0.0.1:8100']);
        Http::allowStrayRequests();                         // the two processes are real; nothing else is asked
        Http::post("{$this->sim}/sim/reset")->throw();
    }

    protected function tearDown(): void
    {
        if (isset($this->sim)) {
            Http::post("{$this->sim}/sim/reset");
        }
        parent::tearDown();
    }

    private function plan(Customer $customer, string $code): Customer
    {
        Subscription::query()->create(['customer_id' => $customer->id, 'package_id' => Package::query()->where('code', $code)->value('id'), 'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        return $customer;
    }

    /**
     * A seeker on the plan, with a wish (the server asks for one before anything else).
     *
     * @param  array<string, mixed>  $wish
     * @param  array<string, mixed>  $account
     */
    private function seeker(array $wish = [], array $account = []): Customer
    {
        $seeker = Customer::factory()->seeker()->create($account);
        BuyerPreference::factory()->for($seeker)->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'budget_max' => 900000, ...$wish]);

        return $this->signIn($this->plan($seeker, 'seeker'));
    }

    /**
     * A published listing, in the index as publishing puts it there.
     *
     * @param  array<string, mixed>  $fields
     */
    private function listing(string $desc, array $fields = []): Listing
    {
        $listing = Listing::factory()->published()->create(['desc_clean' => $desc, 'desc_raw' => $desc, ...$fields]);
        $this->assertNotNull(app(IndexSync::class)->listing($listing), 'the index signal is recorded');

        return $listing;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array{int, float}> id and score of each result, in order
     */
    private function ranked(TestResponse $response, string $path, string $record): array
    {
        return array_map(fn (array $row) => [$row[$record]['id'], $row['score']], $response->json($path));
    }

    /** @return array<string, mixed> everything that reached the node */
    private function seen(): array
    {
        return Http::get("{$this->sim}/sim/seen")->throw()->json();
    }

    /**
     * The six offers whose order the rules decide: every wish · one of two · none · over the budget · far over · fewer rooms.
     *
     * @return array<string, Listing>
     */
    private function offers(): array
    {
        return [
            'all' => $this->listing('شقة بمدخل خاص وفيها مصعد', ['price' => 880000]),
            'half' => $this->listing('شقة مدخلها خاص، بدون مصعد', ['price' => 860000]),
            'none' => $this->listing('شقة واسعة', ['price' => 850000]),
            'over' => $this->listing('شقة بمدخل خاص ومصعد', ['price' => 950000]),
            'far' => $this->listing('شقة بمدخل خاص ومصعد', ['price' => 1200000]),
            'small' => $this->listing('شقة بمدخل خاص ومصعد', ['price' => 700000, 'bedrooms' => 2]),
        ];
    }

    public function test_the_node_is_the_simulation_and_says_so(): void
    {
        $health = Http::get(config('zon.agents.url').'/agents/health')->throw()->json();

        $this->assertSame(['sim-rules', true, true, true, true], [$health['model'], $health['simulated'], $health['llm'], $health['embed'], $health['qdrant']]);
    }

    public function test_search_ranks_by_the_wishes_the_budget_and_the_rooms_and_charges_once(): void
    {
        $o = $this->offers();
        $seeker = $this->seeker(account: ['name' => 'خالد المطيري']);
        $search = ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'budget_max' => 900000, 'bedrooms' => 3,
            'query' => 'أنا خالد المطيري، أبي مدخل خاص ومصعد، كلمني '.self::PHONE];

        $response = $this->postJson('/api/v1/ai/search', $search)->assertOk()
            ->assertJsonPath('meta.ai', ['status' => 'ok', 'simulated' => true])->assertHeader('X-AI-Limit', '30')->assertHeader('X-AI-Remaining', '29');
        $this->apiContract('ai_search', $response);

        $this->assertSame([
            [$o['all']->id, 0.95],       // both wishes, within the budget
            [$o['half']->id, 0.7],       // «بدون مصعد» is no lift: one wish of two
            [$o['over']->id, 0.7],       // both wishes, over the budget within 10%: one step down
            [$o['none']->id, 0.45],
            [$o['small']->id, 0.05],     // fewer rooms than asked
            [$o['far']->id, 0.05],       // over the budget by more than 10%
        ], $this->ranked($response, 'data.results', 'listing'));

        $reached = json_encode($this->seen(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('مدخل خاص ومصعد', $reached);
        foreach ([self::PHONE, 'خالد', 'المطيري', $seeker->phone, $o['all']->customer->name] as $private) {
            $this->assertStringNotContainsString((string) $private, $reached, 'no name and no number reaches the node');
        }
        $this->assertSame(['ok', 'search_listings'], [AiRequest::query()->sole()->status->value, AiRequest::query()->sole()->agent->value]);
    }

    public function test_a_node_that_fails_gives_the_database_order_and_charges_nothing(): void
    {
        $o = $this->offers();
        $this->seeker();
        $search = ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'budget_max' => 900000, 'query' => 'مدخل خاص ومصعد'];
        $ids = fn (TestResponse $r) => array_column($this->ranked($r, 'data.results', 'listing'), 0);

        foreach (['invalid', 'down'] as $failure) {
            Http::post("{$this->sim}/sim/mode", ['llm' => $failure])->throw();
            $response = $this->postJson('/api/v1/ai/search', $search)->assertOk()->assertJsonPath('meta.ai.status', 'degraded')->assertHeader('X-AI-Remaining', '30');
            $this->assertEqualsCanonicalizing(array_map(fn (Listing $l) => $l->id, array_values($o)), $ids($response), "{$failure}: every offer still shown");
            $this->assertNotSame($o['all']->id, $ids($response)[0], "{$failure}: not the model's order");
        }

        Http::post("{$this->sim}/sim/reset")->throw();
        $back = $this->postJson('/api/v1/ai/search', $search)->assertOk()->assertJsonPath('meta.ai.status', 'ok')->assertHeader('X-AI-Remaining', '29');
        $this->assertSame($o['all']->id, $ids($back)[0]);
    }

    public function test_matching_reads_the_wish_and_orders_the_same_type_the_other_types_and_the_projects(): void
    {
        $o = $this->offers();
        $villa = $this->listing('فيلا بمدخل خاص ومصعد', ['property_type' => 'villa', 'price' => 890000, 'title' => 'فيلا للبيع']);
        $project = Project::factory()->published()->create(['neighborhood' => 'النرجس']);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id, 'property_type' => 'apartment', 'price' => 870000]);
        $this->seeker(['bedrooms' => 3, 'notes' => 'مدخل خاص ومصعد، للتواصل '.self::PHONE]);

        $response = $this->postJson('/api/v1/ai/matching')->assertOk()->assertJsonPath('meta.ai', ['status' => 'ok', 'simulated' => true])
            ->assertJsonPath('data.suggest_request', false)->assertHeader('X-AI-Remaining', '29');
        $this->apiContract('ai_matching', $response);

        $this->assertSame([[$o['all']->id, 0.95], [$o['half']->id, 0.7], [$o['over']->id, 0.7], [$o['none']->id, 0.45], [$o['small']->id, 0.05], [$o['far']->id, 0.05]],
            $this->ranked($response, 'data.matched', 'listing'));
        $this->assertSame([[$villa->id, 0.95]], $this->ranked($response, 'data.broaden', 'listing'), 'another type that meets the wish, within the budget');
        $this->assertSame([$project->id, $unit->id], [$response->json('data.projects.0.project.id'), $response->json('data.projects.0.units.0.unit.id')]);
        $this->assertStringNotContainsString(self::PHONE, json_encode($this->seen(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_broker_finds_the_requests_his_offer_answers(): void
    {
        $request = fn (string $desc, array $fields = []) => tap(SearchRequest::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'description' => $desc, 'budget_min' => null, ...$fields]),
            fn (SearchRequest $r) => app(IndexSync::class)->request($r));
        $all = $request('أبي شقة فيها مصعد وموقف', ['budget_max' => 950000]);
        $half = $request('شقة بمسبح ومصعد', ['budget_max' => 900000]);
        $poor = $request('شقة فيها مصعد', ['budget_max' => 600000]);
        $this->signIn($this->plan(Customer::factory()->broker()->create(), Package::BROKER_MONTH));

        $response = $this->postJson('/api/v1/ai/search/requests', ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment', 'budget_max' => 880000,
            'query' => 'عندي شقة فيها مصعد وموقف خاص'])->assertOk()->assertJsonPath('meta.ai', ['status' => 'ok', 'simulated' => true]);
        $this->apiContract('ai_search_requests', $response);

        $ranked = $this->ranked($response, 'data.results', 'request');
        $this->assertSame([[$all->id, 0.95], [$half->id, 0.7]], array_slice($ranked, 0, 2));
        $this->assertNotContains($poor->id, array_column(array_slice($ranked, 0, 2), 0), 'a budget the offer is far over is never among the first');
    }

    public function test_a_developer_finds_the_seekers_each_with_the_unit_model_that_fits(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $project = Project::factory()->published()->create(['developer_id' => $developer->id, 'neighborhood' => 'النرجس']);
        $small = ProjectUnit::factory()->create(['project_id' => $project->id, 'property_type' => 'villa', 'price' => 1000000, 'bedrooms' => 3, 'desc_clean' => 'فيلا بمطبخ راكب']);
        $large = ProjectUnit::factory()->create(['project_id' => $project->id, 'property_type' => 'villa', 'price' => 1400000, 'bedrooms' => 4, 'desc_clean' => 'فيلا بمسبح وغرفة سائق']);
        $wish = fn (array $fields) => BuyerPreference::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['villa'], ...$fields]);
        $pool = $wish(['budget_max' => 1450000, 'bedrooms' => 4, 'notes' => 'ودي مسبح وسائق، جوالي '.self::PHONE]);
        $kitchen = $wish(['budget_max' => 1020000, 'bedrooms' => 3, 'notes' => 'مسبح ومطبخ راكب']);

        $response = $this->postJson("/api/v1/ai/matching/projects/{$project->id}/searchers")->assertOk()->assertJsonPath('meta.ai', ['status' => 'ok', 'simulated' => true]);
        $this->apiContract('ai_project_searchers', $response);

        $this->assertSame([
            [$pool->customer->username, 0.95, $large->id],       // both his wishes are in the model he can afford
            [$kitchen->customer->username, 0.7, $small->id],     // one of two
        ], array_map(fn (array $row) => [$row['seeker']['username'], $row['score'], $row['unit']['id'] ?? null], $response->json('data')));
        $reached = json_encode($this->seen(), JSON_UNESCAPED_UNICODE);
        foreach ([self::PHONE, $pool->customer->name, $pool->customer->phone] as $private) {
            $this->assertStringNotContainsString((string) $private, $reached);
        }
    }

    public function test_a_summary_is_the_notes_own_words_without_the_clients_name(): void
    {
        $broker = $this->signIn($this->plan(Customer::factory()->broker()->create(), Package::BROKER_MONTH));
        $client = CrmClient::query()->create(['broker_id' => $broker->id, 'name' => 'خالد المطيري', 'phone' => '+966550000001', 'city' => 'الرياض']);
        ClientNote::query()->create(['client_id' => $client->id, 'category' => 'interested', 'body' => 'خالد المطيري يبحث عن شقة في النرجس. ميزانيته 900 ألف.']);
        ClientNote::query()->create(['client_id' => $client->id, 'category' => 'cash', 'body' => 'جاهز كاش ومستعجل. يريد المعاينة هذا الأسبوع.']);

        $response = $this->postJson('/api/v1/ai/summaries', ['scope' => 'client', 'id' => $client->id])->assertOk()->assertJsonPath('meta.ai', ['status' => 'ok', 'simulated' => true]);

        $this->assertSame(['العميل يبحث عن شقة في النرجس. جاهز كاش ومستعجل.', ['كاش', 'مستعجل'], 'medium'],
            [$response->json('data.summary'), $response->json('data.signals'), $response->json('data.confidence')]);
        $this->assertStringNotContainsString('خالد', json_encode($this->seen(), JSON_UNESCAPED_UNICODE));
    }

    public function test_the_chat_answers_from_the_library_and_from_the_plans_and_nothing_else(): void
    {
        $document = KbDocument::query()->create(['title' => 'الترخيص الإعلاني', 'category' => 'rega', 'lang' => 'ar', 'version' => '1', 'effective_date' => now()->subMonth(),
            'source' => 'الهيئة العامة للعقار', 'review_owner' => 'فريق زون', 'next_review' => now()->addYear(), 'is_active' => true,
            'body' => 'الترخيص الإعلاني شرط لنشر أي إعلان عقاري. ينتهي الإعلان حين ينتهي الترخيص الإعلاني.']);   // the simulated vectors know shared words only
        $this->assertNotNull(app(IndexSync::class)->kbDocument($document));
        $this->seeker();

        $knowledge = $this->postJson('/api/v1/ai/chat', ['message' => 'متى ينتهي الترخيص الإعلاني؟'])->assertOk()
            ->assertJsonPath('data.intent', 'knowledge')->assertJsonPath('data.covered', true)->assertJsonPath('meta.ai', ['status' => 'ok', 'simulated' => true]);
        $this->apiContract('ai_chat', $knowledge);
        $this->assertSame([$document->body, $document->id], [$knowledge->json('data.answer'), $knowledge->json('data.sources.0.doc_id')]);

        $plans = $this->postJson('/api/v1/ai/chat', ['message' => 'كم سعر الباقة؟'])->assertOk()->assertJsonPath('data.intent', 'packages')->assertJsonPath('data.action', '/account/plans');
        $this->assertStringStartsWith('باقات زون الحالية: ', $plans->json('data.answer'));
        $this->assertStringContainsString('199 ريال', $plans->json('data.answer'));

        $this->postJson('/api/v1/ai/chat', ['message' => 'وش رأيك بالمباراة؟'])->assertOk()->assertJsonPath('data.intent', 'out_of_scope')->assertJsonPath('meta.ai.status', 'skipped');
        $this->assertSame(2, count($this->seen()['llm']), 'out of scope never reaches the node');
        $this->apiContract('ai_chat_history', $this->getJson('/api/v1/ai/chat')->assertOk()->assertJsonCount(3, 'data'));
    }

    public function test_the_wording_of_a_listing_is_the_advertisers_own_words_cleaned(): void
    {
        $listing = Listing::factory()->create(['text_review' => TextReview::Pending,
            'desc_raw' => 'شقة للبيع 🔥🔥 فرصة لا تعوض!! مدخل خاص ومطبخ راكب، بدون مصعد. للتواصل '.self::PHONE]);

        CleanListingText::dispatchSync($listing->id);

        $listing->refresh();
        $this->assertSame([TextReview::Ready, 'شقة للبيع مدخل خاص ومطبخ راكب، بدون مصعد. للتواصل', ['مدخل خاص', 'مطبخ راكب', 'صك', 'يقبل البنك']],     // «[رقم]» of the masking is gone too
            [$listing->text_review, $listing->desc_suggested, $listing->features]);
        $this->assertStringNotContainsString(self::PHONE, json_encode($this->seen(), JSON_UNESCAPED_UNICODE));
    }
}
