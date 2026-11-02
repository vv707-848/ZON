<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ScheduledJob;
use App\Models\DailyAreaStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One row per day × neighbourhood × type, so market reports never read the live tables (§11):
 *   requests of the day (JSON_TABLE spreads each request over its neighbourhoods and types) ·
 *   the median budget of the last 30 days and the median price of what is published (window functions) ·
 *   the share of «غير مطابق» answers in the area over 30 days.
 */
class ComputeDailyAreaStats extends ScheduledJob
{
    private const SPREAD = "FROM requests r
        JOIN JSON_TABLE(r.neighborhoods, '$[*]' COLUMNS (neighborhood VARCHAR(80) PATH '$')) n
        JOIN JSON_TABLE(r.property_types, '$[*]' COLUMNS (property_type VARCHAR(12) PATH '$')) t";

    public function __construct(public readonly ?string $day = null) {}

    public function handle(): void
    {
        $day = $this->day === null ? CarbonImmutable::now('Asia/Riyadh')->subDay()->startOfDay() : CarbonImmutable::parse($this->day, 'Asia/Riyadh')->startOfDay();
        [$from, $to, $window] = [$day->utc()->toDateTimeString(), $day->addDay()->utc()->toDateTimeString(), $day->subDays(29)->utc()->toDateTimeString()];
        /** @var array<string, array<string, mixed>> $stats  "city|neighborhood|type" => row */
        $stats = [];
        $set = function (object $r, string $field, mixed $value) use (&$stats): void {
            $key = "{$r->city}|{$r->neighborhood}|{$r->property_type}";
            $stats[$key] ??= ['city' => $r->city, 'neighborhood' => $r->neighborhood, 'property_type' => $r->property_type,
                'requests_count' => 0, 'listings_count' => 0, 'median_budget' => null, 'median_price' => null, 'rejection_rate' => null];
            $stats[$key][$field] = $value;
        };

        foreach (DB::select('SELECT r.city, n.neighborhood, t.property_type, COUNT(*) AS c '.self::SPREAD.' WHERE r.created_at >= ? AND r.created_at < ? GROUP BY 1, 2, 3', [$from, $to]) as $r) {
            $set($r, 'requests_count', (int) $r->c);
        }
        foreach ($this->medians('SELECT r.city, n.neighborhood, t.property_type, r.budget_max AS v '.self::SPREAD.' WHERE r.created_at >= ? AND r.created_at < ? AND r.budget_max IS NOT NULL', [$window, $to]) as $r) {
            $set($r, 'median_budget', $r->median);
        }
        $listings = "SELECT city, neighborhood, property_type, price AS v FROM listings WHERE status = 'published' AND deleted_at IS NULL";
        foreach ($this->medians($listings, []) as $r) {
            $set($r, 'median_price', $r->median);
        }
        foreach (DB::select("SELECT city, neighborhood, property_type, COUNT(*) AS c FROM listings WHERE status = 'published' AND deleted_at IS NULL GROUP BY 1, 2, 3") as $r) {
            $set($r, 'listings_count', (int) $r->c);
        }
        $rejections = DB::select("SELECT JSON_UNQUOTE(JSON_EXTRACT(query_snapshot, '$.city')) AS city, JSON_UNQUOTE(JSON_EXTRACT(query_snapshot, '$.neighborhood')) AS neighborhood,
                SUM(is_match = 0) / COUNT(*) AS rate FROM search_feedback WHERE created_at >= ? AND created_at < ? GROUP BY 1, 2 HAVING city IS NOT NULL AND neighborhood IS NOT NULL", [$window, $to]);
        foreach ($rejections as $r) {
            foreach ($stats as $key => $stat) {
                if ($stat['city'] === $r->city && $stat['neighborhood'] === $r->neighborhood) {
                    $stats[$key]['rejection_rate'] = round((float) $r->rate, 4);
                }
            }
        }

        DB::transaction(function () use ($day, $stats) {
            DailyAreaStat::query()->whereDate('stat_date', $day->toDateString())->delete();
            $rows = [];
            foreach ($stats as $stat) {
                $rows[] = $stat + ['stat_date' => $day->toDateString()];
            }
            DailyAreaStat::query()->insert($rows);
        });
    }

    /**
     * The median of `v` per (city, neighborhood, property_type) of a query, with window functions.
     *
     * @param  list<mixed>  $bindings
     * @return list<object{city: string, neighborhood: string, property_type: string, median: float}>
     */
    private function medians(string $source, array $bindings): array
    {
        return DB::select("SELECT city, neighborhood, property_type, AVG(v) AS median FROM (
                SELECT city, neighborhood, property_type, v,
                       ROW_NUMBER() OVER (PARTITION BY city, neighborhood, property_type ORDER BY v) AS rn,
                       COUNT(*) OVER (PARTITION BY city, neighborhood, property_type) AS cnt
                FROM ({$source}) AS s
            ) AS ranked WHERE rn IN (FLOOR((cnt + 1) / 2), CEIL((cnt + 1) / 2)) GROUP BY city, neighborhood, property_type", $bindings);
    }
}
