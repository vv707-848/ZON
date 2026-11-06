<?php

namespace App\SuperAgent\Templates;

use App\Enums\Region;
use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/**
 * الطلب والعرض في مدن منطقة (تقرير سوق، ومن خريطة «تقارير السوق»): لكل مدينة في المنطقة: طلبات الفترة، ومتوسط العروض
 * المنشورة يومياً، والطلب لكل عرض — المدن ذات الطلبات الكافية فقط.
 */
final class CityDemandSupply extends MarketTemplate
{
    public function name(): string
    {
        return 'city_demand_supply';
    }

    public function title(): string
    {
        return 'الطلب والعرض في مدن منطقة (تقرير سوق)';
    }

    public function description(): string
    {
        return 'لكل مدينة في منطقة إدارية: طلبات الفترة مقابل متوسط العروض المنشورة يومياً، والطلب لكل عرض (فوق 1: الطلب أكثر من العرض).';
    }

    public function params(): array
    {
        return ['region' => Param::oneOf('المنطقة', array_map(fn (Region $r) => $r->label(), Region::cases()), required: true),
            'property_type' => Param::propertyType(), 'days' => Param::days()];
    }

    public function columns(): array
    {
        return ['city' => 'المدينة', 'requests' => 'الطلبات', 'avg_listings' => 'متوسط العروض', 'demand_per_listing' => 'الطلب لكل عرض'];
    }

    public function query(array $params): array
    {
        $type = $params['property_type'] === null ? '' : ' AND property_type = ?';

        return ["SELECT city, SUM(requests_count) AS requests, ROUND(SUM(listings_count) / COUNT(DISTINCT stat_date), 1) AS avg_listings,
                ROUND(SUM(requests_count) / GREATEST(SUM(listings_count) / COUNT(DISTINCT stat_date), 1), 2) AS demand_per_listing
            FROM ai_v_daily_area_stats WHERE region = ? AND stat_date >= ?{$type}
            GROUP BY city HAVING requests >= ? ORDER BY demand_per_listing DESC",
            [Region::fromLabel($params['region'])?->value, now()->subDays($params['days'])->toDateString(), ...array_filter([$params['property_type']]), self::minimumGroup()]];
    }
}
