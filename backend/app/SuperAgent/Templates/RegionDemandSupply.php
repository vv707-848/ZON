<?php

namespace App\SuperAgent\Templates;

use App\Enums\Region;
use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/**
 * الطلب والعرض حسب المنطقة (تقرير سوق، وخريطة «تقارير السوق»): لكل منطقة إدارية: طلبات الفترة على أحيائها، ومتوسط
 * العروض المنشورة يومياً، والطلب لكل عرض — المناطق ذات الطلبات الكافية فقط.
 */
final class RegionDemandSupply extends MarketTemplate
{
    public function name(): string
    {
        return 'region_demand_supply';
    }

    public function title(): string
    {
        return 'الطلب والعرض حسب المنطقة (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل منطقة إدارية: طلبات الفترة مقابل متوسط العروض المنشورة يومياً، والطلب لكل عرض (فوق 1: الطلب أكثر من العرض). للمقارنة بين المناطق.';
    }

    public function params(): array
    {
        return ['property_type' => Param::propertyType(), 'days' => Param::days()];
    }

    public function columns(): array
    {
        return ['region_name' => 'المنطقة', 'cities' => 'المدن', 'requests' => 'الطلبات', 'avg_listings' => 'متوسط العروض', 'demand_per_listing' => 'الطلب لكل عرض'];
    }

    public function query(array $params): array
    {
        $names = 'CASE region'.str_repeat(' WHEN ? THEN ?', count(Region::cases())).' END';
        $type = $params['property_type'] === null ? '' : ' AND property_type = ?';

        return ["SELECT region, {$names} AS region_name, COUNT(DISTINCT city) AS cities, SUM(requests_count) AS requests,
                ROUND(SUM(listings_count) / COUNT(DISTINCT stat_date), 1) AS avg_listings,
                ROUND(SUM(requests_count) / GREATEST(SUM(listings_count) / COUNT(DISTINCT stat_date), 1), 2) AS demand_per_listing
            FROM ai_v_daily_area_stats WHERE region IS NOT NULL AND stat_date >= ?{$type}
            GROUP BY region HAVING requests >= ? ORDER BY demand_per_listing DESC",
            [...array_merge(...array_map(fn (Region $r) => [$r->value, $r->label()], Region::cases())),
                now()->subDays($params['days'])->toDateString(), ...array_filter([$params['property_type']]), self::minimumGroup()]];
    }
}
