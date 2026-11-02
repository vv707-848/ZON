<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ScheduledJob;
use App\Models\DailyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Yesterday's counters for the dashboard (one row per metric and dimension, Riyadh's day). Re-running a day
 * replaces its rows. A day can be recomputed with `new ComputeDailyMetrics('2026-11-01')`.
 */
class ComputeDailyMetrics extends ScheduledJob
{
    public function __construct(public readonly ?string $day = null) {}

    public function handle(): void
    {
        $day = $this->day === null ? CarbonImmutable::now('Asia/Riyadh')->subDay()->startOfDay() : CarbonImmutable::parse($this->day, 'Asia/Riyadh')->startOfDay();
        [$from, $to] = [$day->utc(), $day->addDay()->utc()];
        $between = fn (string $column) => fn ($q) => $q->where($column, '>=', $from)->where($column, '<', $to);

        $rows = [];
        $put = function (string $metric, string $dimension, float|int|string|null $value) use (&$rows, $day) {
            $rows[] = ['stat_date' => $day->toDateString(), 'metric' => $metric, 'dimension' => $dimension, 'value' => (float) $value];
        };

        foreach (DB::table('customers')->where($between('created_at'))->groupBy('role')->pluck(DB::raw('COUNT(*)'), 'role') as $role => $count) {
            $put('signups', $role, $count);
        }
        $put('listings.published', '', DB::table('listings')->where($between('published_at'))->count());
        $put('listings.sold', '', DB::table('listings')->where($between('sold_at'))->count());
        $put('projects.published', '', DB::table('projects')->where($between('published_at'))->count());
        $put('requests.created', '', DB::table('requests')->where($between('created_at'))->count());
        $put('requests.urgent', '', DB::table('boost_usages')->where('kind', 'urgent_request')->where($between('created_at'))->count());
        $put('preferences.created', '', DB::table('buyer_preferences')->where($between('created_at'))->count());
        foreach (DB::table('payment_transactions')->whereIn('status', ['paid', 'unapplied'])->where($between('paid_at'))->groupBy('purpose')->pluck(DB::raw('SUM(amount_halalas)'), 'purpose') as $purpose => $halalas) {
            $put('revenue', $purpose, $halalas / 100);
        }
        $put('payments.failed', '', DB::table('payment_transactions')->where('status', 'failed')->where($between('updated_at'))->count());
        foreach (DB::table('ai_requests')->where($between('created_at'))->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status') as $status => $count) {
            $put('ai.calls', $status, $count);
        }
        $put('ai.tokens', '', DB::table('ai_requests')->where($between('created_at'))->sum(DB::raw('tokens_in + tokens_out')));
        $put('ai.search_misses', '', DB::table('search_misses')->where($between('created_at'))->count());
        $put('reports.new', '', DB::table('content_reports')->where($between('created_at'))->count());
        $put('tickets.new', '', DB::table('support_tickets')->where($between('created_at'))->count());
        $put('index.unacked', '', DB::table('index_events')->whereNull('acked_at')->where($between('created_at'))->count());

        DB::transaction(function () use ($day, $rows) {
            DailyMetric::query()->whereDate('stat_date', $day->toDateString())->delete();
            DailyMetric::query()->insert($rows);
        });
    }
}
