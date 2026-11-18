<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** خريطة الطلب الموحّدة (تقرير سوق): لكل حي ونوع: الباحثون والرغبات والطلبات معاً، مقابل العروض المنشورة. */
final class DemandMap extends MarketTemplate
{
    public function name(): string
    {
        return 'demand_map';
    }

    public function title(): string
    {
        return 'خريطة الطلب الموحّدة (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل حي ونوع في مدينة، للشراء أو الاستئجار: الباحثون بالبحث الدلالي في الفترة، والرغبات النشطة، والطلبات المرفوعة، مقابل العروض المنشورة الآن، والطلب لكل عرض.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'purpose' => Param::purpose(), 'property_type' => Param::propertyType(), 'days' => Param::days(90), 'limit' => Param::limit(30)];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'searchers' => 'باحثون', 'wishes' => 'رغبات نشطة', 'requests' => 'طلبات',
            'listings' => 'عروض منشورة', 'demand_per_listing' => 'الطلب لكل عرض'];
    }

    public function query(array $params): array
    {
        [$demand, $bindings] = self::demand($params['city'], $params['purpose'], self::since($params['days']));

        return ['SELECT neighborhood, property_type, searchers, wishes, requests, listings,
                ROUND((searchers + wishes + requests) / GREATEST(listings, 1), 2) AS demand_per_listing
            FROM (SELECT x.neighborhood, '.self::typeName('x.property_type').' AS property_type,
                COUNT(DISTINCT CASE WHEN x.src = \'search\' THEN x.who END) AS searchers,
                COUNT(DISTINCT CASE WHEN x.src = \'wish\' THEN x.who END) AS wishes,
                COUNT(DISTINCT CASE WHEN x.src = \'request\' THEN x.who END) AS requests,
                (SELECT COUNT(*) FROM ai_v_listings l WHERE l.status = \'published\' AND l.city = ? AND l.purpose = ?
                    AND l.neighborhood = x.neighborhood AND (x.property_type IS NULL OR l.property_type = x.property_type)) AS listings
            FROM ('.$demand.') x WHERE (? IS NULL OR x.property_type = ?)
            GROUP BY x.neighborhood, x.property_type) g
            WHERE searchers + wishes + requests >= ? ORDER BY demand_per_listing DESC, searchers + wishes + requests DESC LIMIT '.(int) $params['limit'],
            [$params['city'], $params['purpose'], ...$bindings, $params['property_type'], $params['property_type'], self::minimumGroup()]];
    }
}
