<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** فجوة الطلب والعرض (تقرير سوق): لكل حي ونوع: مجموع طلبات الفترة مقابل متوسط العروض المنشورة يومياً — الأحياء ذات الطلبات الكافية فقط. */
final class DemandSupplyGap extends MarketTemplate
{
    public function name(): string
    {
        return 'demand_supply_gap';
    }

    public function title(): string
    {
        return 'فجوة الطلب والعرض (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل حي ونوع: مجموع طلبات الفترة مقابل متوسط العروض المنشورة يومياً — الأحياء ذات الطلبات الكافية فقط.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'days' => Param::days()];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'requests' => 'الطلبات', 'avg_listings' => 'متوسط العروض', 'gap' => 'الفجوة'];
    }

    public function query(array $params): array
    {
        return ['SELECT neighborhood, property_type, SUM(requests_count) AS requests, ROUND(AVG(listings_count), 1) AS avg_listings,
                SUM(requests_count) - ROUND(AVG(listings_count)) AS gap
            FROM ai_v_daily_area_stats WHERE city = ? AND stat_date >= ? GROUP BY neighborhood, property_type HAVING requests >= ? ORDER BY gap DESC',
            [$params['city'], now()->subDays($params['days'])->toDateString(), self::minimumGroup()]];
    }
}
