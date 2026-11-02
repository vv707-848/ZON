<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** الطلب غير المغطى (تقرير سوق): الرغبات النشطة التي لا يقابلها أي عرض منشور في حيها ونوعها ضمن ميزانيتها — من شاركوا في التقارير فقط. */
final class UnmetFeatures extends MarketTemplate
{
    public function name(): string
    {
        return 'unmet_features';
    }

    public function title(): string
    {
        return 'الطلب غير المغطى (تقرير سوق)';
    }

    public function description(): string
    {
        return 'الرغبات النشطة التي لا يقابلها أي عرض منشور في حيها ونوعها ضمن ميزانيتها — من شاركوا في التقارير فقط.';
    }

    public function params(): array
    {
        return ['city' => Param::city()];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'wishes' => 'الرغبات', 'unmet' => 'بلا عرض مناسب', 'unmet_share' => 'نسبة غير المغطى'];
    }

    public function query(array $params): array
    {
        return ["SELECT n.neighborhood, t.property_type, COUNT(DISTINCT w.id) AS wishes,
                COUNT(DISTINCT CASE WHEN NOT EXISTS (SELECT 1 FROM ai_v_listings l WHERE l.status = 'published' AND l.city = w.city AND l.neighborhood = n.neighborhood
                    AND l.property_type = t.property_type AND (w.budget_max IS NULL OR l.price <= w.budget_max)) THEN w.id END) AS unmet,
                ROUND(COUNT(DISTINCT CASE WHEN NOT EXISTS (SELECT 1 FROM ai_v_listings l WHERE l.status = 'published' AND l.city = w.city AND l.neighborhood = n.neighborhood
                    AND l.property_type = t.property_type AND (w.budget_max IS NULL OR l.price <= w.budget_max)) THEN w.id END) / COUNT(DISTINCT w.id), 2) AS unmet_share
            FROM ai_v_preferences w JOIN ".self::items('w.neighborhoods', 'neighborhood', 80).' n
            JOIN '.self::items('w.property_types', 'property_type', 12).' t
            WHERE w.city = ? AND w.is_active = 1 GROUP BY n.neighborhood, t.property_type HAVING wishes >= ? ORDER BY unmet_share DESC, wishes DESC',
            [$params['city'], self::minimumGroup()]];
    }
}
