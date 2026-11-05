<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\SuperAgent as SuperAgentPage;
use App\Jobs\Scheduled\ComputeDailyAreaStats;
use App\Models\BuyerPreference;
use App\Models\ExecReport;
use App\Models\Listing;
use App\Models\SearchRequest;
use App\SuperAgent\Catalog;
use App\SuperAgent\MarketTemplate;
use App\SuperAgent\SuperAgent;
use App\Support\Permissions\Permission;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** The SuperAgent: approved templates only, checked parameters, read-only views, numbers that come from the rows. */
class SuperAgentTest extends AdminTestCase
{
    /** What the fake agents answer next; changed by agentsAnswer(). */
    private array $select = [];

    private array $write = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The read-only connection shares the test's transaction, so it sees what the test created.
        DB::connection('report')->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());

        // Registered once: a second Http::fake() would sit behind the first callback and reset the sent log.
        $answer = fn (array $output) => Http::response(['output' => $output, 'degraded' => false, 'usage' => ['tokens_in' => 10, 'tokens_out' => 10], 'latency_ms' => 500]);
        Http::fake(fn (Sent $r) => match (true) {
            str_ends_with($r->url(), '/agents/report_select') => $answer($this->select),
            str_ends_with($r->url(), '/agents/report_write') => $answer($this->write),
            default => null,
        });
    }

    private function agentsAnswer(array $select, array $write): void
    {
        [$this->select, $this->write] = [$select, $write];
    }

    private function demand(): void
    {
        SearchRequest::factory()->count(3)->create(['neighborhoods' => ['النرجس'], 'urgent' => false]);
        SearchRequest::factory()->create(['neighborhoods' => ['الملقا']]);
    }

    public function test_a_question_becomes_an_approved_report_with_a_checked_analysis(): void
    {
        $this->demand();
        $this->agentsAnswer(
            ['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض', 'days' => 30]],
            ['analysis' => 'النرجس الأعلى طلباً بـ 3 طلبات مقابل 1 في الملقا.', 'recommendations' => ['استقطاب عروض في النرجس']],
        );
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->ask($staff, 'وين الطلب أعلى في الرياض؟');

        $this->assertSame(['ok', 'top_demand_by_neighborhood'], [$report->status, $report->report]);
        $this->assertSame(['النرجس', 3], [$report->rows[0]['neighborhood'], $report->rows[0]['requests']]);
        Http::assertSent(fn (Sent $r) => str_ends_with($r->url(), '/agents/report_select') && count($r['catalog']) === count(Catalog::all())
            && $r['catalog'][0]['params']['days'] === ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30]);
    }

    public function test_an_invented_number_drops_the_analysis_and_a_parameter_out_of_range_runs_nothing(): void
    {
        $this->demand();
        $this->agentsAnswer(
            ['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض', 'days' => 30]],
            ['analysis' => 'الطلب في النرجس ارتفع 47٪ عن الشهر الماضي.', 'recommendations' => []],
        );
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->ask($staff, 'كيف الطلب؟');
        $this->assertSame('no_analysis', $report->status, 'the rows stay, the analysis with a made-up 47 does not');
        $this->assertNotEmpty($report->rows);

        $this->agentsAnswer(['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض', 'days' => 900]], []);
        $this->assertSame('failed', app(SuperAgent::class)->ask($staff, 'آخر 900 يوم')->status);
        Http::assertSentCount(3);
    }

    public function test_every_template_runs_on_the_views(): void
    {
        Listing::factory()->published()->create();
        $this->demand();
        BuyerPreference::factory()->create();
        $staff = $this->signInSuperadmin();
        Settings::put(Setting::SuperAgentDailyCap, 100);

        foreach (Catalog::all() as $name => $template) {
            $params = array_filter(['city' => 'الرياض', 'property_type' => 'apartment'], fn ($v, $k) => isset($template->params()[$k]), ARRAY_FILTER_USE_BOTH);
            $report = app(SuperAgent::class)->run($staff, $template, $params, $template->title(), analyse: false);
            $this->assertNotSame('failed', $report->status, "{$name} failed to run");
        }
        $this->assertSame(count(Catalog::all()), ExecReport::query()->count());
    }

    public function test_market_reports_never_show_a_group_under_the_minimum(): void
    {
        $this->demand();                                       // 3 requests in النرجس: under 5
        (new ComputeDailyAreaStats(now('Asia/Riyadh')->toDateString()))->handle();
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->run($staff, Catalog::find('demand_supply_gap'), ['city' => 'الرياض', 'days' => 30], 'فجوة', analyse: false);

        $this->assertSame(5, MarketTemplate::minimumGroup());
        $this->assertSame([], $report->rows);
    }

    public function test_only_the_superadmin_opens_it_and_the_daily_cap_holds(): void
    {
        $this->signInStaff(...Permission::cases());
        $this->get(SuperAgentPage::getUrl())->assertForbidden();

        $staff = $this->signInSuperadmin();
        Settings::put(Setting::SuperAgentDailyCap, 1);
        Livewire::test(SuperAgentPage::class)->fillForm(['template' => 'listing_funnel', 'days' => 30])->call('manual')->assertSee('مسار العروض');
        $this->assertSame(1, ExecReport::query()->where('staff_id', $staff->id)->count());

        Livewire::test(SuperAgentPage::class)->fillForm(['template' => 'listing_funnel', 'days' => 30])->call('manual')->assertNotified('بلغت سقف التقارير اليومي.');
    }
}
