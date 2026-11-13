<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/**
 * أسعار البيع الفعلية (تقرير سوق): ما قال المعلنون إنه بيع به لكل حي ونوع، مقابل سعر عرضه، في الفترة. لا يُحسب إلا
 * ما ذُكر سعر بيعه، ولا يظهر حي ونوع بأقل من الحد الأدنى من البيوع.
 */
final class SalePrices extends MarketTemplate
{
    public function name(): string
    {
        return 'sale_prices';
    }

    public function title(): string
    {
        return 'أسعار البيع الفعلية (تقرير سوق)';
    }

    public function description(): string
    {
        return 'متوسط سعر البيع الذي ذكره المعلنون لكل حي ونوع، مقابل متوسط سعر العرض، ونسبة الفرق. لا يدخل إلا ما ذُكر سعر بيعه.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'property_type' => Param::propertyType(), 'days' => Param::days(180)];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'sales' => 'البيوع', 'avg_sale_price' => 'متوسط سعر البيع',
            'avg_listed_price' => 'متوسط سعر العرض', 'sale_to_listed' => 'البيع إلى العرض'];
    }

    public function query(array $params): array
    {
        return ["SELECT neighborhood, property_type, SUM(count) AS sales, ROUND(SUM(sale_price * count) / SUM(count)) AS avg_sale_price,
                ROUND(SUM(listed_price * count) / SUM(count)) AS avg_listed_price,
                ROUND(SUM(sale_price * count) / NULLIF(SUM(listed_price * count), 0), 2) AS sale_to_listed
            FROM ai_v_sales
            WHERE purpose = 'sale' AND sale_price IS NOT NULL AND listed_price IS NOT NULL AND city = ? AND (? IS NULL OR property_type = ?) AND created_at >= ?
            GROUP BY neighborhood, property_type HAVING SUM(count) >= ? ORDER BY sales DESC",
            [$params['city'], $params['property_type'], $params['property_type'], self::since($params['days']), self::minimumGroup()]];
    }
}
