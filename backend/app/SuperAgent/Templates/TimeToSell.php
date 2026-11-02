<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** مدة البيع: متوسط الأيام من النشر إلى البيع لكل نوع عقار، للعروض المباعة في الفترة. */
final class TimeToSell extends Template
{
    public function name(): string
    {
        return 'time_to_sell';
    }

    public function title(): string
    {
        return 'مدة البيع';
    }

    public function description(): string
    {
        return 'متوسط الأيام من النشر إلى البيع لكل نوع عقار، للعروض المباعة في الفترة.';
    }

    public function params(): array
    {
        return ['city' => Param::city(required: false), 'days' => Param::days(90)];
    }

    public function columns(): array
    {
        return ['property_type' => 'النوع', 'sold' => 'المباع', 'avg_days' => 'متوسط الأيام'];
    }

    public function query(array $params): array
    {
        return ["SELECT property_type, COUNT(*) AS sold, ROUND(AVG(DATEDIFF(sold_at, published_at)), 1) AS avg_days FROM ai_v_listings
            WHERE status = 'sold' AND sold_at >= ? AND published_at IS NOT NULL AND (? IS NULL OR city = ?) GROUP BY property_type ORDER BY sold DESC",
            [self::since($params['days']), $params['city'], $params['city']]];
    }
}
