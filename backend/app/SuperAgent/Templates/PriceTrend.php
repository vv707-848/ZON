<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** اتجاه الأسعار: متوسط أسعار العروض المنشورة شهرياً لنوع في مدينة. */
final class PriceTrend extends Template
{
    public function name(): string
    {
        return 'price_trend';
    }

    public function title(): string
    {
        return 'اتجاه الأسعار';
    }

    public function description(): string
    {
        return 'متوسط أسعار العروض المنشورة شهرياً لنوع في مدينة.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'neighborhood' => Param::neighborhood(), 'property_type' => Param::propertyType(required: true), 'months' => Param::months()];
    }

    public function columns(): array
    {
        return ['month' => 'الشهر', 'listings' => 'العروض', 'avg_price' => 'متوسط السعر'];
    }

    public function query(array $params): array
    {
        return ["SELECT DATE_FORMAT(published_at, '%Y-%m') AS month, COUNT(*) AS listings, ROUND(AVG(price)) AS avg_price FROM ai_v_listings
            WHERE city = ? AND (? IS NULL OR neighborhood = ?) AND property_type = ? AND published_at >= ? GROUP BY month ORDER BY month",
            [$params['city'], $params['neighborhood'], $params['neighborhood'], $params['property_type'], now()->subMonths($params['months'])->startOfMonth()->toDateTimeString()]];
    }
}
