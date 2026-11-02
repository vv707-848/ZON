<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** العرض مقابل الطلب: لكل حي ونوع: طلبات الفترة مقابل العروض المنشورة الآن، والفجوة بينهما. */
final class SupplyVsDemand extends Template
{
    public function name(): string
    {
        return 'supply_vs_demand';
    }

    public function title(): string
    {
        return 'العرض مقابل الطلب';
    }

    public function description(): string
    {
        return 'لكل حي ونوع: طلبات الفترة مقابل العروض المنشورة الآن، والفجوة بينهما.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'days' => Param::days()];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'requests' => 'الطلبات', 'listings' => 'العروض المنشورة', 'gap' => 'الفجوة'];
    }

    public function query(array $params): array
    {
        $since = self::since($params['days']);

        return ['SELECT d.neighborhood, d.property_type, d.requests, COALESCE(s.listings, 0) AS listings, d.requests - COALESCE(s.listings, 0) AS gap
            FROM (SELECT n.neighborhood, t.property_type, COUNT(DISTINCT r.id) AS requests FROM ai_v_requests r JOIN '.self::items('r.neighborhoods', 'neighborhood', 80).' n JOIN '.self::items('r.property_types', 'property_type', 12)." t
                  WHERE r.city = ? AND r.created_at >= ? GROUP BY n.neighborhood, t.property_type) d
            LEFT JOIN (SELECT neighborhood, property_type, COUNT(*) AS listings FROM ai_v_listings WHERE city = ? AND status = 'published' GROUP BY neighborhood, property_type) s
              ON s.neighborhood = d.neighborhood AND s.property_type = d.property_type
            ORDER BY gap DESC", [$params['city'], $since, $params['city']]];
    }
}
