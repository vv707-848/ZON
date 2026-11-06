<?php

namespace Tests\Feature\Admin;

use App\Enums\PropertyType;
use App\Enums\Region;
use App\Filament\Pages\MarketReports;
use App\Filament\Support\MarketMap;
use App\Jobs\Scheduled\ComputeDailyAreaStats;
use App\Models\City;
use App\Models\ExecReport;
use App\Models\Listing;
use App\Models\SearchRequest;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * «خريطة السوق»: each region coloured by demand per listing, a region with too few requests hatched (never «balanced»),
 * its cities on a click — all from the daily snapshots, through the read-only views. The region is not the app's.
 */
class MarketMapTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('report')->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());
    }

    /** $count requests for apartments in one neighbourhood of $city, and $listings published apartments there. */
    private function area(string $city, string $neighborhood, int $count, int $listings): void
    {
        SearchRequest::factory()->count($count)->create(['city' => $city, 'neighborhoods' => [$neighborhood], 'property_types' => ['apartment']]);
        Listing::factory()->published()->count($listings)->create(['city' => $city, 'neighborhood' => $neighborhood, 'property_type' => PropertyType::Apartment]);
    }

    private function market(): void
    {
        $this->area('الرياض', 'النرجس', 6, 1);       // 6 per listing: the demand far ahead
        $this->area('الدمام', 'الفيصلية', 5, 10);     // 0.5 in the city
        $this->area('الخبر', 'العقربية', 0, 2);       // no request: not a city row, but its listings count for the region (5 / 12)
        $this->area('جدة', 'الروضة', 2, 0);           // under the minimum of 5: hatched
        (new ComputeDailyAreaStats(now('Asia/Riyadh')->toDateString()))->handle();
    }

    /** The path of a region as the map draws it: its class and its click. */
    private static function path(string $class, Region $region): string
    {
        return 'class="'.$class.'" tabindex="0" wire:click="pickRegion(\''.$region->value.'\')"';
    }

    public function test_each_region_is_coloured_by_demand_per_listing_and_too_few_requests_are_hatched(): void
    {
        $this->market();
        $this->signInSuperadmin();

        Livewire::test(MarketReports::class)
            ->assertSeeHtml(self::path('zon-k4', Region::Riyadh))
            ->assertSeeHtml(self::path('zon-k0', Region::Eastern))
            ->assertSeeHtml(self::path('zon-none', Region::Makkah))
            ->assertSeeHtml(self::path('zon-none', Region::Tabuk))
            ->assertSeeInOrder(['المنطقة', 'منطقة الرياض', '6', 'الطلب أكثر بكثير', 'المنطقة الشرقية', '0.42', 'العرض أكثر بكثير'])
            ->assertSee('منطقة مكة المكرمة — لا بيانات كافية');
        $this->assertSame([0, 1, 2, 2, 3, 4], array_map(MarketMap::band(...), [0.49, 0.5, 0.8, 1.24, 1.25, 2.0]));
    }

    public function test_a_picked_region_shows_its_cities_and_the_filters_narrow_the_map(): void
    {
        $this->market();
        $this->signInSuperadmin();

        $page = Livewire::test(MarketReports::class)->call('pickRegion', Region::Eastern->value)
            ->assertSet('region', 'SA-04')->assertSee('مدن المنطقة الشرقية')->assertDontSeeHtml('<td>الخبر</td>')
            ->assertSeeHtml('class="zon-k0 zon-on"')->assertSeeHtml('<td>الدمام</td><td>5</td><td>10</td><td>0.5</td><td>العرض أكثر</td>');
        $page->call('pickRegion', Region::Eastern->value)->assertSet('region', null)->assertDontSee('مدن المنطقة الشرقية');
        $page->set('filters.property_type', 'villa')->assertSee('لا توجد منطقة بطلبات كافية في هذه الفترة.')
            ->assertSeeHtml(self::path('zon-none', Region::Riyadh));
    }

    public function test_each_market_report_asks_for_its_own_parameters(): void
    {
        $this->market();
        $this->signInSuperadmin();
        Http::fake(fn (Sent $r) => str_ends_with($r->url(), '/agents/report_write')
            ? Http::response(['output' => ['analysis' => 'الدمام وحدها فوق الحد الأدنى.', 'recommendations' => []], 'degraded' => false,
                'usage' => ['tokens_in' => 1, 'tokens_out' => 1], 'latency_ms' => 10])
            : null);

        Livewire::test(MarketReports::class)
            ->callAction(TestAction::make('city_demand_supply')->schemaComponent(true, 'content'), ['region' => 'المنطقة الشرقية', 'days' => 30])->assertHasNoActionErrors()
            ->callAction(TestAction::make('real_budget_index')->schemaComponent(true, 'content'), ['city' => 'الرياض', 'property_type' => 'apartment'])->assertHasNoActionErrors();

        $reports = ExecReport::query()->orderBy('id')->get();
        $this->assertSame(['city_demand_supply', 'real_budget_index'], $reports->map(fn (ExecReport $r) => $r->parts[0]['report'])->all());
        $this->assertSame([['الدمام', 5]], array_map(fn (array $row) => [$row['city'], (int) $row['requests']], $reports[0]->parts[0]['rows']));
    }

    public function test_cities_carry_their_region_and_the_app_never_sees_it(): void
    {
        $this->assertSame([Region::Riyadh, Region::Makkah, Region::Eastern, Region::Eastern],
            City::query()->orderBy('sort')->pluck('region')->all());

        $this->getJson('/api/v1/catalog')->assertOk()->assertDontSee('SA-0')->assertDontSee('region');
    }
}
