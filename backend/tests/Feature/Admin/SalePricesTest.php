<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Models\SaleReport;
use App\SuperAgent\Catalog;
use App\SuperAgent\SuperAgent;
use Illuminate\Support\Facades\DB;

/**
 * «تم البيع» as the market sees it (decision of 13 November): the sale price is asked, never required of a broker or a
 * developer — some would type any number — and only what was said is reported. «أسعار البيع الفعلية» reads it by
 * area and type, never a group under the minimum, never a tester's sale.
 */
class SalePricesTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('report')->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());
    }

    public function test_each_sale_is_reported_with_the_price_he_gave_or_none(): void
    {
        $broker = $this->signIn(Customer::factory()->broker()->create());
        $said = Listing::factory()->published()->for($broker)->create(['price' => 900000]);
        $silent = Listing::factory()->published()->for($broker)->create(['price' => 700000]);
        $this->postJson("/api/v1/listings/{$said->id}/sold", ['sale_price' => 870000])->assertOk();
        $this->postJson("/api/v1/listings/{$silent->id}/sold")->assertOk();

        $developer = $this->signIn(Customer::factory()->developer()->create());
        $project = Project::factory()->published()->create(['developer_id' => $developer->id]);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id, 'price' => 1950000, 'available_count' => 6]);
        $this->postJson("/api/v1/projects/{$project->id}/units/{$unit->id}/sold", ['count' => 2, 'sale_price' => 1900000])->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/units/{$unit->id}/sold", ['count' => 1])->assertOk();

        $rows = SaleReport::query()->orderBy('id')->get()->map(fn (SaleReport $r) => [$r->subject_type, $r->count, (string) $r->listed_price, $r->sale_price === null ? null : (string) $r->sale_price])->all();
        $this->assertSame([
            ['listing', 1, '900000.00', '870000.00'],
            ['listing', 1, '700000.00', null],          // no price is no price: not the listed one
            ['project_unit', 2, '1950000.00', '1900000.00'],
            ['project_unit', 1, '1950000.00', null],
        ], $rows);
    }

    public function test_the_market_report_shows_said_prices_by_area_and_never_a_small_group_or_a_tester(): void
    {
        $report = fn (string $neighborhood, ?string $sale, bool $tester = false, int $count = 1) => SaleReport::query()->create([
            'customer_id' => Customer::factory()->broker()->create(['is_tester' => $tester])->id, 'subject_type' => 'listing', 'subject_id' => 1,
            'count' => $count, 'purpose' => 'sale', 'property_type' => 'villa', 'city' => 'الرياض', 'neighborhood' => $neighborhood,
            'listed_price' => 2000000, 'sale_price' => $sale,
        ]);
        foreach ([1800000, 1900000, 1900000, 2000000] as $price) {
            $report('النرجس', (string) $price);
        }
        $report('النرجس', '1900000', count: 2);             // two units in one sale
        $report('النرجس', null);                             // no price said: not counted
        $report('النرجس', '100', tester: true);               // a tester's: not counted
        $report('الملقا', '1500000', count: 4);              // under five: not shown

        $staff = $this->signInSuperadmin();
        $result = app(SuperAgent::class)->manual($staff, Catalog::find('sale_prices'), ['city' => 'الرياض'], 'أسعار البيع', analyse: false);

        $this->assertSame('ok', $result->parts[0]['status']);
        $rows = array_map(fn (array $r) => [$r['neighborhood'], (int) $r['sales'], (int) $r['avg_sale_price'], (int) $r['avg_listed_price'], (string) $r['sale_to_listed']], $result->parts[0]['rows']);
        $this->assertSame([['النرجس', 6, 1900000, 2000000, '0.95']], $rows);
    }
}
