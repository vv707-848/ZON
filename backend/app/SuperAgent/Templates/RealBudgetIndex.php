<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** مؤشر الميزانية الحقيقية (تقرير سوق): وسيط أعلى ميزانية الطالبين مقابل وسيط أسعار المعروض لكل حي، وفارقهما بالنسبة. */
final class RealBudgetIndex extends MarketTemplate
{
    public function name(): string
    {
        return 'real_budget_index';
    }

    public function title(): string
    {
        return 'مؤشر الميزانية الحقيقية (تقرير سوق)';
    }

    public function description(): string
    {
        return 'وسيط أعلى ميزانية الطالبين مقابل وسيط أسعار المعروض لكل حي، وفارقهما بالنسبة.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'property_type' => Param::propertyType()];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'median_budget' => 'وسيط الميزانية', 'median_price' => 'وسيط السعر', 'budget_to_price' => 'الميزانية إلى السعر'];
    }

    public function query(array $params): array
    {
        return ['SELECT s.neighborhood, s.property_type, s.median_budget, s.median_price, ROUND(s.median_budget / NULLIF(s.median_price, 0), 2) AS budget_to_price
            FROM ai_v_daily_area_stats s
            WHERE s.city = ? AND (? IS NULL OR s.property_type = ?) AND s.stat_date = (SELECT MAX(stat_date) FROM ai_v_daily_area_stats)
              AND s.median_budget IS NOT NULL AND s.median_price IS NOT NULL
              AND (SELECT SUM(x.requests_count) FROM ai_v_daily_area_stats x WHERE x.city = s.city AND x.neighborhood = s.neighborhood AND x.property_type = s.property_type
                   AND x.stat_date >= DATE_SUB(s.stat_date, INTERVAL 29 DAY)) >= ?
            ORDER BY budget_to_price', [$params['city'], $params['property_type'], $params['property_type'], self::minimumGroup()]];
    }
}
