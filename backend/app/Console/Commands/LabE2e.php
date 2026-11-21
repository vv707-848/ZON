<?php

namespace App\Console\Commands;

use App\Arena\ArenaSamples;
use App\Arena\TestArena;
use App\Enums\CustomerRole;
use App\Enums\StaffType;
use App\Models\ArenaBatch;
use App\Models\City;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * `php artisan zon:lab-e2e` — search and matching as the app asks them, through Laravel's API, its database, the
 * agents, Qdrant and the model: the time from sending the request to having the answer, eight at once (owner, 21
 * Nov 2026). On a lab node only (deploy/ai-node/lab-pod.sh): it fills the city with a test-arena batch (sandboxed,
 * removed with one button), gives its own tester seekers the seeker plan, empties the answer cache, warms each
 * endpoint once unmeasured, then sends `--at-once` requests at a time, each a different need (no cached answer).
 *
 * Each answer says how it was obtained (`meta.ai.status`): `ok` the model ranked it; `degraded`, `fallback`,
 * `timeout`, `rejected` the other path — counted among the failures, as a refused or failed request is. The report
 * (`--out`) holds every request: endpoint, milliseconds, HTTP status, AI status, results.
 */
class LabE2e extends Command
{
    /** The acceptance limit of a load at production's sizes, eight at once (agents: LabLimits / lab load). */
    public const P95_MS = 15000;

    private const PATHS = ['search' => '/api/v1/ai/search', 'matching' => '/api/v1/ai/matching'];

    /** How an answer counts: the model ranked it, or no call was needed (no candidate): not a failure either. */
    private const RANKED = ['ok', 'skipped'];

    protected $signature = 'zon:lab-e2e
        {--base=http://127.0.0.1:8000 : the API as the app reaches it}
        {--city=الرياض}
        {--listings=300 : listings of the arena batch, indexed by the agents into Qdrant}
        {--at-once=8}
        {--rounds=3}
        {--qdrant= : Qdrant\'s URL: waits until the listings collection holds the batch}
        {--out= : the JSON report}';

    protected $description = 'Search and matching through the API, the agents, Qdrant and the model: p95 eight at once';

    public function handle(TestArena $arena): int
    {
        $atOnce = max(1, (int) $this->option('at-once'));
        $rounds = max(1, (int) $this->option('rounds'));
        $city = (string) $this->option('city');
        $neighborhoods = City::catalog()[$city] ?? [];
        if ($neighborhoods === []) {
            $this->error("no such city: {$city}");

            return self::FAILURE;
        }

        $by = User::query()->where('type', StaffType::Superadmin)->first()
            ?? User::query()->create(['name' => 'lab', 'email' => 'lab-'.Str::random(8).'@zon.test', 'password' => Str::random(40), 'type' => StaffType::Superadmin]);
        $batch = $arena->generate($city, [], (int) $this->option('listings'), 0, $atOnce, 20, $by);
        $this->info("arena batch {$batch->id}: {$batch->listings} listings, {$atOnce} seekers");
        if (($qdrant = $this->option('qdrant')) && ! $this->indexed((string) $qdrant, $batch)) {
            $this->error('the listings did not reach Qdrant');

            return self::FAILURE;
        }

        $tokens = $this->seekers($batch);
        Cache::flush();   // no answer from the cache: each request asks the agents
        $samples = new ArenaSamples("lab-e2e:{$batch->id}");
        $offers = Listing::query()->whereIn('customer_id', Customer::query()->where('arena_batch', $batch->id)->select('id'))->orderBy('id')->get();
        $next = 0;
        // each need is drawn from one of the batch's listings (its place, type, purpose, a budget a little above its
        // price, its rooms) with a sample wish as its words: a need with candidates, so the agents are asked
        $need = function () use ($samples, $city, $neighborhoods, $offers, &$next): array {
            $offer = $offers[($next++ * 7) % max(1, $offers->count())] ?? null;

            return self::body($samples->request($city, $neighborhoods, 20), $offer);
        };
        foreach (self::PATHS as $path) {   // warm-up, not measured
            Http::withToken($tokens[0])->timeout(60)->acceptJson()->post($this->option('base').$path, $need());
        }
        Cache::flush();

        $rows = [];
        foreach (self::PATHS as $endpoint => $path) {
            for ($round = 1; $round <= $rounds; $round++) {
                $bodies = array_map(fn () => $need(), range(1, $atOnce));
                $answers = Http::pool(fn (Pool $pool) => array_map(
                    fn (int $n) => $pool->as((string) $n)->withToken($tokens[$n % count($tokens)])->timeout(60)->acceptJson()
                        ->withHeaders(['X-Device-Id' => "lab-e2e-{$n}"])->post($this->option('base').$path, $bodies[$n]),
                    array_keys($bodies),
                ));
                foreach ($answers as $answer) {
                    $rows[] = self::row($endpoint, $round, $answer);
                }
            }
        }

        $report = self::summary($rows, $atOnce, $rounds, $batch);
        if ($out = $this->option('out')) {
            file_put_contents((string) $out, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
        $this->table(['endpoint', 'asked', 'failed', 'model', 'other path', 'p50 ms', 'p95 ms', 'max ms'], array_map(
            fn (string $e, array $s) => [$e, $s['asked'], $s['failed'], $s['ranked_by_model'], $s['other_path'], $s['p50_ms'], $s['p95_ms'], $s['max_ms']],
            array_keys($report['endpoints']), $report['endpoints'],
        ));
        $this->line($report['passes'] ? 'within the limits: 0 failed, p95 ≤ '.self::P95_MS.' ms' : 'misses the limits: '.implode(', ', $report['misses']));

        return $report['passes'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $wish  ArenaSamples::request
     * @return array<string, mixed> the API's body for it
     */
    public static function body(array $wish, ?Listing $offer = null): array
    {
        $purpose = fn ($p) => $p instanceof \BackedEnum ? $p->value : $p;
        $body = $offer === null ? [
            'city' => $wish['city'],
            'neighborhoods' => $wish['neighborhoods'],
            'property_types' => $wish['property_types'],
            'purpose' => $purpose($wish['purpose']),
            'budget_min' => $wish['budget_min'],
            'budget_max' => $wish['budget_max'],
            'bedrooms' => $wish['rooms_min'],
        ] : [
            'city' => $offer->city,
            'neighborhoods' => [$offer->neighborhood],
            'property_types' => [$purpose($offer->property_type)],
            'purpose' => $purpose($offer->purpose),
            'budget_max' => (int) (ceil((float) $offer->price * 1.15 / 10000) * 10000),
            'bedrooms' => $offer->bedrooms,
        ];

        return array_filter([...$body, 'query' => explode('، ', (string) $wish['description'], 2)[1] ?? $wish['description']], fn ($v) => $v !== null);
    }

    /** @return array{endpoint: string, round: int, ms: int, http: int, ai: ?string, results: int} */
    public static function row(string $endpoint, int $round, mixed $answer): array
    {
        if (! $answer instanceof Response) {   // the connection itself failed: a failure, its time unknown
            return ['endpoint' => $endpoint, 'round' => $round, 'ms' => 0, 'http' => 0, 'ai' => null, 'results' => 0];
        }
        $data = (array) $answer->json('data');

        return [
            'endpoint' => $endpoint,
            'round' => $round,
            'ms' => (int) round(($answer->transferStats?->getTransferTime() ?? 0) * 1000),   // sent → received
            'http' => $answer->status(),
            'ai' => $answer->json('meta.ai.status'),
            'results' => count((array) ($data['results'] ?? $data['matched'] ?? [])),
        ];
    }

    /**
     * @param  list<array{endpoint: string, round: int, ms: int, http: int, ai: ?string, results: int}>  $rows
     * @return array<string, mixed>
     */
    public static function summary(array $rows, int $atOnce, int $rounds, ?ArenaBatch $batch = null): array
    {
        $endpoints = [];
        $misses = [];
        foreach (array_unique(array_column($rows, 'endpoint')) as $endpoint) {
            $own = array_values(array_filter($rows, fn (array $r) => $r['endpoint'] === $endpoint));
            $failed = array_values(array_filter($own, fn (array $r) => $r['http'] !== 200 || ! in_array($r['ai'], self::RANKED, true)));
            $times = array_column(array_filter($own, fn (array $r) => $r['http'] === 200), 'ms');
            sort($times);
            $statuses = array_count_values(array_map(fn (array $r) => $r['http'] === 200 ? (string) $r['ai'] : "http {$r['http']}", $own));
            $p95 = self::percentile($times, 95);
            $endpoints[$endpoint] = [
                'asked' => count($own),
                'failed' => count($failed),
                'ranked_by_model' => $statuses['ok'] ?? 0,
                'no_candidate' => $statuses['skipped'] ?? 0,
                'other_path' => count($own) - ($statuses['ok'] ?? 0) - ($statuses['skipped'] ?? 0),
                'statuses' => $statuses,
                'p50_ms' => self::percentile($times, 50),
                'p95_ms' => $p95,
                'max_ms' => $times === [] ? 0 : max($times),
            ];
            if ($failed !== [] || $p95 > self::P95_MS) {
                $misses[] = $endpoint;
            }
        }

        return [
            'at_once' => $atOnce,
            'rounds' => $rounds,
            'limit_p95_ms' => self::P95_MS,
            'arena_batch' => $batch?->id,
            'endpoints' => $endpoints,
            'passes' => $misses === [],
            'misses' => $misses,
            'requests' => $rows,
        ];
    }

    /** @param list<int> $sorted */
    private static function percentile(array $sorted, int $p): int
    {
        return $sorted === [] ? 0 : $sorted[(int) min(count($sorted) - 1, max(0, (int) ceil($p / 100 * count($sorted)) - 1))];
    }

    /** @return list<string> a token for each of the batch's seekers, each on the seeker plan for a month */
    private function seekers(ArenaBatch $batch): array
    {
        $plan = Package::query()->where('code', Package::SEEKER)->value('id');

        return Customer::query()->where('arena_batch', $batch->id)->where('role', CustomerRole::Seeker)->orderBy('id')->get()
            ->map(function (Customer $seeker) use ($plan): string {
                Subscription::query()->create(['customer_id' => $seeker->id, 'package_id' => $plan, 'source' => 'grant', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

                return $seeker->createToken('lab-e2e')->plainTextToken;
            })->values()->all();
    }

    private function indexed(string $qdrant, ArenaBatch $batch): bool
    {
        for ($try = 0; $try < 120; $try++) {
            $points = (int) Http::get(rtrim($qdrant, '/').'/collections/listings')->json('result.points_count', 0);
            if ($points >= $batch->listings) {
                $this->info("qdrant: {$points} listings");

                return true;
            }
            sleep(5);
        }

        return false;
    }
}
