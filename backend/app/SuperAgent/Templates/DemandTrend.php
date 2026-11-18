<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** اتجاه الطلب (تقرير سوق): لكل شهر: الباحثون والرغبات الجديدة والطلبات، وتغيّر المجموع عن الشهر السابق. */
final class DemandTrend extends MarketTemplate
{
    public function name(): string
    {
        return 'demand_trend';
    }

    public function title(): string
    {
        return 'اتجاه الطلب الشهري (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل شهر بتوقيت الرياض في مدينة (وحي ونوع إن طُلبا)، للشراء أو الاستئجار: الباحثون بالبحث الدلالي، والرغبات الجديدة، والطلبات المرفوعة، ونسبة تغيّر المجموع عن الشهر السابق.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'purpose' => Param::purpose(), 'neighborhood' => Param::neighborhood(), 'property_type' => Param::propertyType(), 'months' => Param::months(6)];
    }

    public function columns(): array
    {
        return ['month' => 'الشهر', 'searchers' => 'باحثون', 'wishes' => 'رغبات جديدة', 'requests' => 'طلبات', 'total' => 'المجموع', 'change' => 'التغيّر عن الشهر السابق'];
    }

    public function query(array $params): array
    {
        $since = now('Asia/Riyadh')->startOfMonth()->subMonths($params['months'] - 1)->utc()->toDateTimeString();
        [$demand, $bindings] = self::demand($params['city'], $params['purpose'], $since, wishesSince: true);

        // the change is from the month before, computed before a month under the minimum is left out
        return ['SELECT month, searchers, wishes, requests, total, change_rate AS `change` FROM (
                SELECT month, searchers, wishes, requests, total,
                    ROUND((total - LAG(total) OVER (ORDER BY month)) / NULLIF(LAG(total) OVER (ORDER BY month), 0), 2) AS change_rate
                FROM (SELECT DATE_FORMAT(x.created_at + INTERVAL 3 HOUR, \'%Y-%m\') AS month,
                    COUNT(DISTINCT CASE WHEN x.src = \'search\' THEN x.who END) AS searchers,
                    COUNT(DISTINCT CASE WHEN x.src = \'wish\' THEN x.who END) AS wishes,
                    COUNT(DISTINCT CASE WHEN x.src = \'request\' THEN x.who END) AS requests,
                    COUNT(DISTINCT x.src, x.who) AS total
                FROM ('.$demand.') x WHERE (? IS NULL OR x.neighborhood = ?) AND (? IS NULL OR x.property_type = ?)
                GROUP BY month) m) t
            WHERE total >= ? ORDER BY month',
            [...$bindings, $params['neighborhood'], $params['neighborhood'], $params['property_type'], $params['property_type'], self::minimumGroup()]];
    }
}
