<?php

namespace Tests\Feature\Admin;

use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use App\Filament\Pages\SuperAgent as SuperAgentPage;
use App\Models\ClientNote;
use App\Models\CrmClient;
use App\Models\Customer;
use App\Models\ExecReport;
use App\SuperAgent\Catalog;
use App\SuperAgent\NotesDigest;
use App\SuperAgent\SuperAgent;
use App\Support\Permissions\Permission;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

/**
 * The brokers' client book in the SuperAgent: counts by place and kind (never a person), and the notes read as
 * words only by the superadmin — masked, from twenty notes up, audited.
 */
class ClientBookReportsTest extends AdminTestCase
{
    /** @var list<list<string>> the texts each summarize_batch call received */
    private array $read = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::connection('report')->setPdo(DB::connection()->getPdo())->setReadPdo(DB::connection()->getPdo());
        Http::fake(function (Sent $r) {
            if (! str_ends_with($r->url(), '/agents/summarize_batch')) {
                return null;
            }
            $this->read[] = $r['texts'] ?? [];

            return Http::response(['output' => ['summary' => 'أغلب العملاء يطلبون دوراً أرضياً قريباً من المدارس، ويرفضون البعد عن الخدمات.',
                'themes' => [['theme' => 'قرب المدارس', 'share' => 'high'], ['theme' => 'الدفع كاش', 'share' => 'medium']]],
                'degraded' => false, 'usage' => ['tokens_in' => 10, 'tokens_out' => 10], 'latency_ms' => 500]);
        });
    }

    /** $count clients of a broker, each with $notes notes naming the client and his phone. */
    private function book(Customer $broker, int $count, int $notes, string $neighborhood = 'النرجس', string $city = 'الرياض'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $client = CrmClient::query()->create(['broker_id' => $broker->id, 'name' => "سالم {$i}", 'phone' => '+96655000000'.$i, 'purpose' => 'sale',
                'city' => $city, 'neighborhoods' => [$neighborhood], 'property_types' => ['apartment'], 'budget_max' => 900000 + $i * 1000]);
            for ($n = 0; $n < $notes; $n++) {
                ClientNote::query()->create(['client_id' => $client->id, 'category' => 'interested', 'body' => "سالم {$i} يريد دوراً أرضياً قرب المدرسة، جواله 05500000{$i}{$n}"]);
            }
        }
    }

    public function test_the_client_book_reports_count_clients_never_name_them(): void
    {
        $staff = $this->signInSuperadmin();
        $this->book(Customer::factory()->broker()->create(), 6, 2);
        $this->book(Customer::factory()->broker()->create(), 1, 1, 'الملقا');
        $this->book(Customer::factory()->broker()->tester()->create(), 9, 3);          // a review account: nowhere

        $clients = app(SuperAgent::class)->manual($staff, Catalog::find('broker_clients'), ['city' => 'الرياض'], 'عملاء الوسطاء', analyse: false);
        $demand = app(SuperAgent::class)->manual($staff, Catalog::find('client_demand'), ['city' => 'الرياض'], 'طلب العملاء', analyse: false);

        [$clients, $demand] = [$clients->parts[0]['rows'], $demand->parts[0]['rows']];
        $this->assertSame([['1-5', 1, 1, 1], ['6-20', 1, 6, 12]], array_map(fn ($r) => [$r['bucket'], (int) $r['brokers'], (int) $r['clients'], (int) $r['notes']], $clients));
        $this->assertSame([['النرجس', 'apartment', 6]], array_map(fn ($r) => [$r['neighborhood'], $r['property_type'], (int) $r['clients']], $demand), 'الملقا has one client: under the minimum');
        $this->assertStringNotContainsString('سالم', json_encode([$clients, $demand], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_superadmin_reads_the_notes_masked_in_a_summary_and_it_is_audited(): void
    {
        $staff = $this->signInSuperadmin();
        $this->book(Customer::factory()->broker()->create(), 25, 1);
        $this->book(Customer::factory()->broker()->create(), 5, 1, 'العليا', 'جدة');      // another city
        $this->book(Customer::factory()->broker()->tester()->create(), 5, 1);             // a review account

        $report = app(NotesDigest::class)->read($staff, ['city' => 'الرياض', 'neighborhood' => 'النرجس', 'category' => 'interested']);

        $sent = array_merge(...$this->read);
        $this->assertCount(25, $sent, "the city's notes, none of a review account");
        $this->assertSame([], array_filter($sent, fn (string $t) => str_contains($t, 'سالم') || preg_match('/05\d{6,}/', $t)), 'no client name, no phone left');
        $this->assertSame([ExecReportStatus::Ok, ExecReportMode::Notes, 'crm_notes_digest', 25], [$report->status, $report->mode, $report->parts[0]['report'], $report->parts[0]['params']['notes']]);
        $this->assertSame('قرب المدارس', $report->parts[0]['rows'][0]['theme']);
        $audit = Activity::query()->where('event', 'crm_notes_read')->sole();
        $this->assertSame(25, $audit->properties['notes']);
        $this->assertStringNotContainsString('المدرسة', (string) $audit->properties, 'the log keeps the filters and a count, never the words');
    }

    public function test_under_twenty_notes_nothing_is_read_and_no_one_else_reads_them(): void
    {
        $staff = $this->signInSuperadmin();
        $this->book(Customer::factory()->broker()->create(), 19, 1);

        $report = app(NotesDigest::class)->read($staff, ['city' => 'الرياض']);

        $this->assertSame([ExecReportStatus::NoAnalysis, []], [$report->status, $this->read]);
        Livewire::test(SuperAgentPage::class)->callAction('notes_digest', ['city' => 'الرياض', 'days' => 180])->assertSee('أقل من 20 ملاحظة');

        $this->expectException(RuntimeException::class);
        app(NotesDigest::class)->read($this->signInStaff(...Permission::cases()), ['city' => 'الرياض']);
    }

    public function test_a_neighbourhood_narrows_a_report_and_one_outside_the_reference_is_refused(): void
    {
        $staff = $this->signInSuperadmin();

        $report = app(SuperAgent::class)->manual($staff, Catalog::find('time_to_sell'), ['city' => 'الرياض', 'neighborhood' => 'النرجس'], 'مدة البيع', analyse: false);
        $this->assertEquals(['city' => 'الرياض', 'neighborhood' => 'النرجس', 'days' => 90], $report->parts[0]['params']);
        $this->assertArrayNotHasKey('enum', ((array) Catalog::find('time_to_sell')->catalogEntry()['params'])['neighborhood'], 'every neighbourhood is checked here, not sent to the model');

        $this->expectException(\InvalidArgumentException::class);
        app(SuperAgent::class)->manual($staff, Catalog::find('time_to_sell'), ['neighborhood' => 'حي لا وجود له'], 'مدة البيع', analyse: false);
    }

    public function test_the_catalog_counts_twenty_two_reports(): void
    {
        $this->assertCount(22, Catalog::all());
        $this->assertSame(0, ExecReport::query()->count());
    }
}
