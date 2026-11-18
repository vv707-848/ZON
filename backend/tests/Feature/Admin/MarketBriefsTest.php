<?php

namespace Tests\Feature\Admin;

use App\Ai\Assistants\SearchDemand;
use App\Ai\Retrieval\Need;
use App\Enums\Purpose;
use App\Filament\Resources\MarketBriefs\MarketBriefResource;
use App\Filament\Resources\MarketBriefs\Pages\CreateMarketBrief;
use App\Filament\Resources\MarketBriefs\Pages\ListMarketBriefs;
use App\Jobs\Scheduled\ComputeDailyAreaStats;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\MarketBrief;
use App\Models\SearchRequest;
use App\SuperAgent\BriefBuilder;
use App\SuperAgent\Catalog;
use App\SuperAgent\ReportRunner;
use App\Support\Permissions\Permission;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * «تقارير المطوّرين»: the market of a city as developers buy it — searches, wishes and requests side by side with what
 * is published, by budget band, rooms and month — every number the views' own, never a group under five, sales and
 * rents never mixed; frozen when made, opened by its link until it expires, and its payment in the accounts.
 */
class MarketBriefsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('report')->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());
    }

    /** @param array<string, mixed> $need */
    private function search(Customer $who, array $need): void
    {
        SearchDemand::record(Need::fromInput($need + ['purpose' => 'sale', 'city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'apartment']), $who);
    }

    /**
     * النرجس, apartments for sale: 3 people searched (one twice) at 800k and 3 rooms; 2 shared wishes at 900k and 3
     * rooms; 1 request at 1.2M and 4 rooms — 6 people. What must not count: a tester's search, a wish kept out of
     * reports, a rent wish and a rent request, a rental listing. Published for sale: 850k (3 rooms) and 1.1M (4 rooms).
     */
    private function market(): void
    {
        foreach (Customer::factory()->seeker()->count(3)->create() as $i => $seeker) {
            $this->search($seeker, ['budget_max' => 800_000, 'bedrooms' => 3]);
            if ($i === 0) {
                $this->search($seeker, ['budget_max' => 800_000, 'bedrooms' => 3]);   // the same person again: still one
            }
        }
        $this->search(Customer::factory()->seeker()->tester()->create(), ['budget_max' => 800_000]);
        BuyerPreference::factory()->count(2)->create(['bedrooms' => 3]);
        BuyerPreference::factory()->create(['share_in_reports' => false]);
        BuyerPreference::factory()->create(['purpose' => Purpose::Rent, 'budget_max' => 60_000]);
        SearchRequest::factory()->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'budget_max' => 1_200_000, 'rooms_min' => 4]);
        SearchRequest::factory()->create(['purpose' => Purpose::Rent, 'neighborhoods' => ['النرجس'], 'property_types' => ['apartment'], 'budget_min' => null, 'budget_max' => 50_000]);
        Listing::factory()->published()->create(['neighborhood' => 'النرجس', 'price' => 850_000, 'bedrooms' => 3]);
        Listing::factory()->published()->create(['neighborhood' => 'النرجس', 'price' => 1_100_000, 'bedrooms' => 4]);
        Listing::factory()->published()->create(['neighborhood' => 'النرجس', 'purpose' => Purpose::Rent, 'price' => 55_000, 'bedrooms' => 3]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $report, array $params): array
    {
        $template = Catalog::find($report);

        return app(ReportRunner::class)->run($template, $template->checkParams($params));
    }

    public function test_a_search_leaves_its_criteria_and_a_monthly_key_never_the_person(): void
    {
        $seeker = Customer::factory()->seeker()->create();
        $this->search($seeker, ['budget_max' => 800_000, 'query' => 'شقة 3 غرف مساحة 150 قرب مدرسة، جوالي 0551234567']);

        $row = (array) DB::table('ai_v_search_demand')->sole();
        $this->assertSame(['sale', 'الرياض', ['النرجس'], ['apartment'], '800000.00', 3, '150.00'],
            [$row['purpose'], $row['city'], json_decode($row['neighborhoods']), json_decode($row['property_types']), $row['budget_max'], $row['bedrooms'], $row['area']]);
        $this->assertSame(SearchDemand::searcher($seeker), $row['searcher']);
        $this->assertSame(['id', 'purpose', 'city', 'neighborhoods', 'property_types', 'budget_max', 'bedrooms', 'area', 'searcher', 'created_at'],
            array_keys($row), 'no account, no text, no device');
        $this->assertSame(['id', 'purpose', 'city', 'neighborhoods', 'property_types', 'budget_max', 'bedrooms', 'area', 'searcher', 'created_at'],
            array_keys((array) DB::table('search_demand')->sole()), 'nor in the table itself');
        $this->travel(1)->months();
        $this->assertNotSame($row['searcher'], SearchDemand::searcher($seeker), 'the key changes each month');
    }

    public function test_the_market_reports_count_people_by_buy_or_rent_and_never_a_small_group(): void
    {
        $this->market();
        $city = ['city' => 'الرياض'];

        $this->assertSame([['neighborhood' => 'النرجس', 'property_type' => 'شقة', 'searchers' => 3, 'wishes' => 2, 'requests' => 1, 'listings' => 2, 'demand_per_listing' => 3.0]],
            $this->rows('demand_map', $city));
        $this->assertSame([['band' => '750 ألف – مليون', 'demand' => 5, 'demand_share' => 0.83, 'listings' => 1, 'demand_per_listing' => 5.0]],
            $this->rows('budget_bands', $city), 'the 1–1.5 million band holds one person: left out, its share still counted');
        $this->assertSame([['band' => '3 غرف', 'demand' => 5, 'demand_share' => 0.83, 'listings' => 1, 'demand_per_listing' => 5.0]], $this->rows('rooms_demand', $city));
        $trend = $this->rows('demand_trend', $city);
        $this->assertSame([3, 2, 1, 6, null], [$trend[0]['searchers'], $trend[0]['wishes'], $trend[0]['requests'], $trend[0]['total'], $trend[0]['change']]);
        $this->assertCount(1, $trend);

        $this->assertSame([], $this->rows('demand_map', $city + ['purpose' => 'rent']), 'one rent wish and one rent request: under five');
        $this->assertSame([], $this->rows('budget_bands', $city + ['neighborhood' => 'الملقا']));
    }

    public function test_the_daily_snapshot_keeps_sales_and_rents_apart(): void
    {
        $this->market();
        (new ComputeDailyAreaStats(now('Asia/Riyadh')->toDateString()))->handle();

        $rows = DB::table('ai_v_daily_area_stats')->where('neighborhood', 'النرجس')->where('property_type', 'apartment')->orderBy('purpose')->get();
        $this->assertSame([['rent', 1, 1, '50000.00', '55000.00'], ['sale', 1, 2, '1200000.00', '975000.00']],
            $rows->map(fn ($r) => [$r->purpose, $r->requests_count, $r->listings_count, $r->median_budget, $r->median_price])->all(),
            'a yearly rent never sits in the median of a sale');
    }

    public function test_a_brief_is_made_frozen_and_opened_by_its_link_until_it_expires(): void
    {
        $this->market();
        $developer = Customer::factory()->developer()->create(['name' => 'شركة سنابل للتطوير']);
        $staff = $this->signInSuperadmin();

        Livewire::test(CreateMarketBrief::class)
            ->fillForm(['city' => 'الرياض', 'purpose' => 'sale', 'days' => 90, 'customer_id' => $developer->id, 'price' => '7500', 'valid_days' => 30,
                'analyst_note' => 'الطلب على الشقق الصغيرة يفوق المعروض.'])
            ->assertSchemaStateSet(['buyer_name' => 'شركة سنابل للتطوير'])
            ->call('create')->assertHasNoFormErrors();

        $brief = MarketBrief::query()->sole();
        $this->assertSame(['ZON-MB-'.str_pad((string) $brief->id, 6, '0', STR_PAD_LEFT), 750_000, $developer->id], [$brief->number, $brief->price_halalas, $brief->customer_id]);
        $this->assertSame(array_keys(BriefBuilder::SECTIONS), array_column($brief->sections, 'report'), 'every section, sale prices too (a sale brief)');
        $this->assertSame([
            'أعلى طلب لكل عرض: النرجس (شقة) — الطلب لكل عرض منشور 3.',
            'أكثر شريحة ميزانية مطلوبة: 750 ألف – مليون ريال — 83٪ من الطالبين، والعروض المنشورة فيها 1.',
            'أكثر ما يُطلب: 3 غرف — 83٪ من الطالبين.',
        ], $brief->highlights);
        $this->assertSame('market_brief_created', Activity::query()->where('causer_id', $staff->id)->value('event'));

        SearchRequest::factory()->count(10)->create(['neighborhoods' => ['النرجس'], 'property_types' => ['apartment']]);
        $page = $this->get('https://zon.sa/market-brief/'.$brief->token)->assertOk()
            ->assertSee('شركة سنابل للتطوير')->assertSee('الطلب على الشقق الصغيرة يفوق المعروض.')->assertSee('أين الطلب');
        $this->assertSame(3, $page->viewData('brief')->sections[0]['rows'][0]['searchers'], 'what was sold does not change with the market');
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow', $page->headers->get('X-Robots-Tag'));
        $this->assertSame(1, $brief->refresh()->views_count);

        $this->get('https://zon.sa/market-brief/'.str_repeat('a', 40))->assertNotFound();
        $this->travel(31)->days();
        $this->get('https://zon.sa/market-brief/'.$brief->token)->assertNotFound();
    }

    public function test_a_rent_brief_has_no_sale_prices_and_a_closed_link_opens_for_no_one(): void
    {
        $this->signInSuperadmin();
        Livewire::test(CreateMarketBrief::class)
            ->fillForm(['city' => 'الرياض', 'purpose' => 'rent', 'days' => 30, 'buyer_name' => 'مستثمر', 'valid_days' => 7])
            ->call('create')->assertHasNoFormErrors();
        $brief = MarketBrief::query()->sole();
        $this->assertNotContains('sale_prices', array_column($brief->sections, 'report'));
        $this->assertSame([], $brief->highlights, 'no data: no headline number');

        Livewire::test(ListMarketBriefs::class)->callTableAction('close', $brief);
        $this->get('https://zon.sa/market-brief/'.$brief->token)->assertNotFound();
    }

    public function test_a_paid_brief_goes_to_the_accounts(): void
    {
        $this->signInSuperadmin();
        Livewire::test(CreateMarketBrief::class)
            ->fillForm(['city' => 'الرياض', 'purpose' => 'sale', 'days' => 90, 'buyer_name' => '=HYPERLINK("x")', 'valid_days' => 30])
            ->call('create')->assertHasNoFormErrors();
        $brief = MarketBrief::query()->sole();

        Livewire::test(ListMarketBriefs::class)->callTableAction('paid', $brief, ['price' => '5000', 'paid_on' => '2026-11-18']);
        $this->assertSame(500_000, $brief->refresh()->price_halalas);

        $download = Livewire::test(ListMarketBriefs::class)->callAction('export_briefs', ['from' => '2026-11-18', 'until' => '2026-11-18'])
            ->assertFileDownloaded('zon-market-briefs-2026-11-18-to-2026-11-18.csv')->__get('effects')['download'];
        $lines = array_map(fn (string $l) => str_getcsv($l, escape: ''), array_values(array_filter(explode("\n", substr(base64_decode($download['content']), 3)))));
        $this->assertSame([$brief->number, '2026-11-18', "'=HYPERLINK(\"x\")", '', 'تقرير سوق: الرياض · للبيع', '5000.00', 'SAR', 'إيراد', 'تحويل'], $lines[1]);
    }

    public function test_only_the_superadmin_sees_the_market_briefs(): void
    {
        $this->signInStaff(...Permission::cases());
        $this->get(MarketBriefResource::getUrl())->assertForbidden();
    }
}
