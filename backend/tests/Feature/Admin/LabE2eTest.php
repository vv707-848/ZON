<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\LabE2e;
use App\Models\Customer;
use App\Models\Subscription;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;

/** zon:lab-e2e: search and matching through the API, eight at once (owner, 21 Nov 2026). */
class LabE2eTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_each_request_is_its_own_need_by_its_own_seeker_and_the_other_path_is_a_failure(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'e2e');
        Http::fake([
            'agents.test/*' => Http::response(['ok' => true]),
            'api.test/api/v1/ai/search' => Http::response(['data' => ['results' => [['score' => 0.9], ['score' => 0.8]]], 'meta' => ['ai' => ['status' => 'ok']]]),
            // the model out of time: the agents' own order, said to be degraded
            'api.test/api/v1/ai/matching' => Http::sequence()->push(['data' => ['matched' => []], 'meta' => ['ai' => ['status' => 'ok']]])
                ->push(['data' => ['matched' => [['score' => null]]], 'meta' => ['ai' => ['status' => 'degraded']]])
                ->whenEmpty(Http::response(['data' => ['matched' => [['score' => 0.7]]], 'meta' => ['ai' => ['status' => 'ok']]])),
        ]);

        $code = $this->artisan('zon:lab-e2e', ['--base' => 'http://api.test', '--listings' => 12, '--at-once' => 4, '--rounds' => 2, '--out' => $out])->run();

        $report = json_decode((string) file_get_contents($out), true);
        $this->assertSame(1, $code, 'a degraded answer misses the limits');
        $this->assertSame(['asked' => 8, 'failed' => 0, 'ranked_by_model' => 8, 'other_path' => 0], array_intersect_key($report['endpoints']['search'], array_flip(['asked', 'failed', 'ranked_by_model', 'other_path'])));
        $this->assertSame(1, $report['endpoints']['matching']['failed'], 'the second matching (the first measured) was degraded');
        $this->assertSame(['matching'], $report['misses']);
        $this->assertCount(16, $report['requests']);

        $measured = Http::recorded(fn (Sent $r) => str_starts_with($r->url(), 'http://api.test/'))->map(fn (array $pair) => $pair[0])->values();
        $this->assertCount(18, $measured, 'one warm-up per endpoint, then 2 × 4 each');
        $bodies = $measured->map(fn (Sent $r) => json_encode($r->data()))->all();
        $this->assertCount(18, array_unique($bodies), 'each a different need: no answer from the cache');
        $this->assertCount(4, $measured->map(fn (Sent $r) => $r->header('Authorization')[0])->unique(), 'one seeker per slot');
        $seekers = Customer::query()->whereNotNull('arena_batch')->where('role', 'seeker')->get();
        $this->assertTrue($seekers->every(fn (Customer $c) => $c->is_tester && Subscription::query()->where('customer_id', $c->id)->exists()));
    }

    public function test_the_percentiles_and_the_limit(): void
    {
        $rows = array_map(fn (int $ms) => ['endpoint' => 'search', 'round' => 1, 'ms' => $ms, 'http' => 200, 'ai' => 'ok', 'results' => 3], range(1000, 20000, 1000));
        $summary = LabE2e::summary($rows, 8, 3);
        $this->assertSame([10000, 19000, 20000], [$summary['endpoints']['search']['p50_ms'], $summary['endpoints']['search']['p95_ms'], $summary['endpoints']['search']['max_ms']]);
        $this->assertFalse($summary['passes'], 'p95 over '.LabE2e::P95_MS);
        $this->assertTrue(LabE2e::summary(array_slice($rows, 0, 10), 8, 3)['passes']);
        $refused = [...array_slice($rows, 0, 3), ['endpoint' => 'search', 'round' => 1, 'ms' => 0, 'http' => 503, 'ai' => null, 'results' => 0]];
        $this->assertSame(['ok' => 3, 'http 503' => 1], LabE2e::summary($refused, 8, 3)['endpoints']['search']['statuses']);
    }
}
