<?php

namespace Tests\Feature\Admin;

use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use App\Filament\Pages\SuperAgent as SuperAgentPage;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Listings\ListingResource;
use App\Jobs\RunExecReport;
use App\Jobs\Scheduled\ComputeDailyAreaStats;
use App\Models\BuyerPreference;
use App\Models\ExecReport;
use App\Models\Listing;
use App\Models\SearchRequest;
use App\SuperAgent\Archive;
use App\SuperAgent\Catalog;
use App\SuperAgent\MarketTemplate;
use App\SuperAgent\NotesDigest;
use App\SuperAgent\NumberGuard;
use App\SuperAgent\Proposal;
use App\SuperAgent\SuperAgent;
use App\Support\Permissions\Permission;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;

/**
 * The SuperAgent: approved templates only, checked parameters, read-only views, numbers that come from the rows —
 * for a message of several questions, an exploration step by step, and proposals that only open a screen.
 */
class SuperAgentTest extends AdminTestCase
{
    /** @var array<string, list<array<string, mixed>>> what each fake agent answers, in order (the last one repeats) */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The read-only connection shares the test's transaction, so it sees what the test created.
        DB::connection('report')->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());

        // Registered once: a second Http::fake() would sit behind the first callback and reset the sent log.
        Http::fake(function (Sent $r) {
            $agent = preg_match('#/agents/([a-z_]+)$#', $r->url(), $m) === 1 ? $m[1] : '';
            if (($this->answers[$agent] ?? []) === []) {
                return null;
            }
            $output = count($this->answers[$agent]) > 1 ? array_shift($this->answers[$agent]) : $this->answers[$agent][0];

            return Http::response(['output' => $output, 'degraded' => false, 'usage' => ['tokens_in' => 10, 'tokens_out' => 10], 'latency_ms' => 500]);
        });
    }

    /** @param array<string, mixed> ...$outputs */
    private function answers(string $agent, array ...$outputs): void
    {
        $this->answers[$agent] = array_values($outputs);
    }

    /** @return list<array<string, mixed>> the payloads sent to one agent, in order, as JSON went out */
    private function sent(string $agent): array
    {
        return Http::recorded(fn (Sent $r) => str_ends_with($r->url(), "/agents/{$agent}"))->map(fn (array $pair) => json_decode($pair[0]->body(), true))->values()->all();
    }

    private function demand(): void
    {
        SearchRequest::factory()->count(3)->create(['neighborhoods' => ['النرجس'], 'urgent' => false]);
        SearchRequest::factory()->create(['neighborhoods' => ['الملقا']]);
    }

    public function test_a_question_becomes_an_approved_report_with_a_checked_analysis(): void
    {
        $this->demand();
        $this->answers('report_select', ['reports' => [['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض', 'days' => 30]]]]);
        $this->answers('report_write', ['analysis' => 'النرجس الأعلى طلباً بـ 3 طلبات مقابل 1 في الملقا.', 'recommendations' => [
            ['text' => 'دعوة وسطاء الرياض إلى عروض في النرجس', 'action' => 'announce', 'params' => ['audience' => 'broker', 'city' => 'الرياض']],
        ]]);
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->ask($staff, 'وين الطلب أعلى في الرياض؟');

        $this->assertSame([ExecReportStatus::Ok, ExecReportMode::Question, ['top_demand_by_neighborhood']], [$report->status, $report->mode, array_column($report->parts, 'report')]);
        $this->assertSame(['النرجس', 3], [$report->parts[0]['rows'][0]['neighborhood'], $report->parts[0]['rows'][0]['requests']]);
        $this->assertEquals([['text' => 'دعوة وسطاء الرياض إلى عروض في النرجس', 'action' => 'announce', 'params' => ['audience' => 'broker', 'city' => 'الرياض', 'kind' => null]]], $report->recommendations);

        [$select] = $this->sent('report_select');
        $this->assertCount(count(Catalog::all()), $select['catalog']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30], $select['catalog'][0]['params']['days']);
        [$write] = $this->sent('report_write');
        $this->assertSame(['top_demand_by_neighborhood', 'الرياض', 'النرجس'], [$write['parts'][0]['report'], $write['parts'][0]['params']['city'], $write['parts'][0]['rows'][0]['neighborhood']]);
        $this->assertSame(array_column(Proposal::catalog(), 'action'), array_column($write['proposals'], 'action'));
    }

    public function test_a_message_of_several_questions_runs_a_report_for_each_and_one_analysis_checked_against_all_their_rows(): void
    {
        $this->demand();
        Listing::factory()->published()->count(13)->create();
        $this->answers('report_select', ['reports' => [
            ['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض']],
            ['report' => 'listing_funnel', 'params' => ['days' => 30]],
            ['report' => 'listing_funnel', 'params' => ['days' => 30]],
        ]]);
        $this->answers('report_write', ['analysis' => 'النرجس الأعلى طلباً، والمنشور 13 عرضاً.', 'recommendations' => []]);

        $report = app(SuperAgent::class)->ask($this->signInSuperadmin(), 'وين الطلب أعلى في الرياض؟ وكم عرض منشور؟');

        $this->assertSame(['top_demand_by_neighborhood', 'listing_funnel'], array_column($report->parts, 'report'), 'the same report twice runs once');
        $this->assertSame(ExecReportStatus::Ok, $report->status, '13 is in the second report\'s rows');
        [$write] = $this->sent('report_write');
        $this->assertSame(['top_demand_by_neighborhood', 'listing_funnel'], array_column($write['parts'], 'report'));
    }

    public function test_one_report_out_of_range_refuses_the_whole_answer_and_a_question_no_report_answers_runs_nothing(): void
    {
        $this->demand();
        $staff = $this->signInSuperadmin();
        $this->answers('report_select', ['reports' => [
            ['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض', 'days' => 30]],
            ['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض', 'days' => 900]],
        ]]);
        $refused = app(SuperAgent::class)->ask($staff, 'آخر 30 يوماً وآخر 900 يوم');

        $this->answers('report_select', ['reports' => []]);
        $unanswered = app(SuperAgent::class)->ask($staff, 'ما عاصمة فرنسا؟');

        $this->assertSame([[ExecReportStatus::Failed, []], [ExecReportStatus::Failed, []]], [[$refused->status, $refused->parts], [$unanswered->status, $unanswered->parts]]);
        $this->assertSame([], $this->sent('report_write'));
        Livewire::withQueryParams(['report' => $unanswered->id])->test(SuperAgentPage::class)->assertSee('لم يُفهم الطلب ضمن التقارير المعتمدة');
    }

    public function test_an_invented_number_drops_the_analysis_and_keeps_the_rows(): void
    {
        $this->demand();
        $this->answers('report_select', ['reports' => [['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض']]]]);
        $this->answers('report_write', ['analysis' => 'الطلب في النرجس ارتفع 47٪ عن الشهر الماضي.', 'recommendations' => []]);

        $report = app(SuperAgent::class)->ask($this->signInSuperadmin(), 'كيف الطلب؟');

        $this->assertSame([ExecReportStatus::NoAnalysis, null], [$report->status, $report->analysis], 'the analysis with a made-up 47 is not kept');
        $this->assertNotEmpty($report->parts[0]['rows']);
    }

    public function test_a_small_number_is_checked_too_and_a_count_of_rows_is_data(): void
    {
        $guard = new NumberGuard;
        $this->assertFalse($guard->passes('الإيراد 12 مليون ريال', [['revenue' => 280]], []), 'no blanket pass for numbers up to 12');
        $this->assertTrue($guard->passes('ثلاثة أحياء، والثاني منها النرجس', [['requests' => 38]], []), 'counts in words are not numbers');

        $this->demand();                                       // two neighbourhoods: النرجس and الملقا
        $this->answers('report_select', ['reports' => [['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض']]]]);
        $this->answers('report_write', ['analysis' => 'أعلى 2 من الأحياء: النرجس ثم الملقا.', 'recommendations' => []]);

        $report = app(SuperAgent::class)->ask($this->signInSuperadmin(), 'وين الطلب أعلى؟');

        $this->assertSame(ExecReportStatus::Ok, $report->status, 'the report has 2 rows: «2» is data');
    }

    public function test_an_exploration_takes_each_step_from_what_it_found_and_stops_when_the_picture_is_clear(): void
    {
        $this->demand();
        $this->answers('report_explore',
            ['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض']],
            ['report' => 'time_to_sell', 'params' => ['city' => 'الرياض', 'neighborhood' => 'النرجس']],
            ['report' => null, 'params' => []],
        );
        $this->answers('report_write', ['analysis' => 'النرجس الأعلى طلباً في الرياض.', 'recommendations' => []]);

        $report = app(SuperAgent::class)->explore($this->signInSuperadmin(), 'الطلب في الرياض');

        $this->assertSame([ExecReportMode::Explore, ExecReportStatus::Ok, ['top_demand_by_neighborhood', 'time_to_sell']], [$report->mode, $report->status, array_column($report->parts, 'report')]);
        $steps = $this->sent('report_explore');
        $this->assertCount(3, $steps);
        $this->assertSame(['الطلب في الرياض', [], 4], [$steps[0]['focus'], $steps[0]['found'], $steps[0]['steps_left']]);
        $this->assertSame(['top_demand_by_neighborhood', 'النرجس', 'الحي', 3], [$steps[1]['found'][0]['report'], $steps[1]['found'][0]['rows'][0]['neighborhood'], $steps[1]['found'][0]['columns']['neighborhood'], $steps[1]['steps_left']]);
        [$write] = $this->sent('report_write');
        $this->assertStringStartsWith('استكشاف: الطلب في الرياض', $write['question']);
        $this->assertCount(2, $write['parts']);
        Livewire::withQueryParams(['report' => $report->id])->test(SuperAgentPage::class)
            ->assertSeeInOrder(['الطلب في الرياض', 'النرجس الأعلى طلباً في الرياض.', 'أكثر الأحياء طلباً', 'مدة البيع']);
    }

    public function test_an_exploration_never_takes_a_step_twice_nor_more_than_four(): void
    {
        $staff = $this->signInSuperadmin();
        $this->answers('report_write', ['analysis' => 'لا جديد.', 'recommendations' => []]);
        $this->answers('report_explore', ['report' => 'listing_funnel', 'params' => ['days' => 30]]);
        $repeated = app(SuperAgent::class)->explore($staff, '');

        $this->answers('report_explore', ...array_map(fn (int $days) => ['report' => 'listing_funnel', 'params' => ['days' => $days]], [7, 30, 90, 180, 365]));
        $long = app(SuperAgent::class)->explore($staff, '');

        $this->assertCount(1, $repeated->parts, 'the same step again ends the exploration');
        $this->assertSame([7, 30, 90, 180], array_map(fn (array $part) => $part['params']['days'], $long->parts));
        $this->assertCount(2 + SuperAgent::EXPLORE_STEPS, $this->sent('report_explore'));
    }

    public function test_a_proposal_outside_the_catalog_loses_its_button_not_its_text_and_a_kept_one_opens_its_screen(): void
    {
        $this->demand();
        $staff = $this->signInSuperadmin();
        $this->answers('report_select', ['reports' => [['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض']]]]);
        $this->answers('report_write', ['analysis' => 'النرجس الأعلى طلباً.', 'recommendations' => [
            ['text' => 'حذف العروض القديمة', 'action' => 'delete_listings', 'params' => []],
            ['text' => 'إشعار للجميع', 'action' => 'announce', 'params' => ['audience' => 'everyone']],
            ['text' => 'مراجعة عروض الرياض المنتظرة', 'action' => 'review_listings', 'params' => ['city' => 'الرياض', 'status' => 'pending_review']],
            ['text' => 'دعوة وسطاء الرياض', 'action' => 'announce', 'params' => ['audience' => 'broker', 'city' => 'الرياض']],
        ]]);

        $report = app(SuperAgent::class)->ask($staff, 'وين الطلب أعلى؟');

        $this->assertSame(['حذف العروض القديمة', 'إشعار للجميع', 'مراجعة عروض الرياض المنتظرة', 'دعوة وسطاء الرياض'], array_column($report->recommendations, 'text'));
        $this->assertSame([null, null, 'review_listings', 'announce'], array_column($report->recommendations, 'action'));
        $listings = ListingResource::getUrl('index', ['filters' => ['city' => ['value' => 'الرياض'], 'status' => ['value' => 'pending_review']]]);
        Livewire::withQueryParams(['report' => $report->id])->test(SuperAgentPage::class)
            ->assertSeeHtml(e($listings))->assertSee(Proposal::Announce->title())->assertDontSee(Proposal::ReviewAccounts->title());

        Livewire::withQueryParams(['audience' => 'broker', 'city' => 'الرياض'])->test(CreateAnnouncement::class)
            ->assertSchemaStateSet(['audience' => 'broker', 'city' => 'الرياض', 'kind' => null, 'title' => null], 'form');
    }

    public function test_a_report_runs_in_the_background_one_at_a_time_and_the_page_polls_until_it_is_ready(): void
    {
        Queue::fake();
        $this->demand();
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->ask($staff, 'وين الطلب أعلى في الرياض؟');

        $this->assertSame(ExecReportStatus::Running, $report->status);
        Queue::assertPushedOn('ai', RunExecReport::class, fn (RunExecReport $job) => $job->reportId === $report->id);
        Livewire::withQueryParams(['report' => $report->id])->test(SuperAgentPage::class)->assertSeeHtml('wire:poll.2s')->assertSee('جارٍ إعداد التقرير');
        $this->assertThrows(fn () => app(SuperAgent::class)->explore($staff, ''), RuntimeException::class, 'تقرير قيد الإعداد');

        $this->answers('report_select', ['reports' => [['report' => 'top_demand_by_neighborhood', 'params' => ['city' => 'الرياض']]]]);
        $this->answers('report_write', ['analysis' => 'النرجس الأعلى طلباً.', 'recommendations' => []]);
        (new RunExecReport($report->id))->handle(app(SuperAgent::class), app(NotesDigest::class));

        $this->assertSame(ExecReportStatus::Ok, $report->refresh()->status);
        Livewire::withQueryParams(['report' => $report->id])->test(SuperAgentPage::class)->assertDontSeeHtml('wire:poll')->assertSee('النرجس الأعلى طلباً.');
    }

    public function test_a_report_whose_job_died_is_failed_and_holds_no_one_back(): void
    {
        Queue::fake();
        $staff = $this->signInSuperadmin();
        $killed = app(SuperAgent::class)->ask($staff, 'سؤال');
        (new RunExecReport($killed->id))->failed(new RuntimeException('timed out'));

        $lost = app(SuperAgent::class)->ask($staff, 'سؤال آخر');                        // its worker vanished
        $this->travel(ExecReport::STALE_AFTER_SECONDS + 1)->seconds();

        $this->assertSame([ExecReportStatus::Failed, ExecReportStatus::Running, ExecReportStatus::Failed], [$killed->refresh()->status, $lost->refresh()->status, $lost->shownStatus()]);
        $this->assertFalse(Archive::running($staff));
    }

    public function test_every_template_runs_on_the_views(): void
    {
        Listing::factory()->published()->create();
        $this->demand();
        BuyerPreference::factory()->create();
        $staff = $this->signInSuperadmin();
        Settings::put(Setting::SuperAgentDailyCap, 100);

        foreach (Catalog::all() as $name => $template) {
            $params = array_filter(['city' => 'الرياض', 'property_type' => 'apartment', 'region' => 'منطقة الرياض'], fn ($v, $k) => isset($template->params()[$k]), ARRAY_FILTER_USE_BOTH);
            $report = app(SuperAgent::class)->manual($staff, $template, $params, $template->title(), analyse: false);
            $this->assertSame('ok', $report->parts[0]['status'], "{$name} failed to run");
        }
        $this->assertSame(count(Catalog::all()), ExecReport::query()->count());
    }

    public function test_market_reports_never_show_a_group_under_the_minimum(): void
    {
        $this->demand();                                       // 3 requests in النرجس: under 5
        (new ComputeDailyAreaStats(now('Asia/Riyadh')->toDateString()))->handle();
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->manual($staff, Catalog::find('demand_supply_gap'), ['city' => 'الرياض', 'days' => 30], 'فجوة', analyse: false);

        $this->assertSame(5, MarketTemplate::minimumGroup());
        $this->assertSame([], $report->parts[0]['rows']);
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
