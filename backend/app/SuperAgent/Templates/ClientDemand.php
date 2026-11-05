<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** طلب عملاء الوسطاء (تقرير سوق): ما يطلبه عملاء الوسطاء في كل حي ونوع — عددهم ووسيط أعلى ميزانيتهم. */
final class ClientDemand extends MarketTemplate
{
    public function name(): string
    {
        return 'client_demand';
    }

    public function title(): string
    {
        return 'طلب عملاء الوسطاء (تقرير سوق)';
    }

    public function description(): string
    {
        return 'ما يطلبه عملاء الوسطاء المسجّلون في إدارة العملاء لكل حي ونوع: عددهم ومتوسط أعلى ميزانيتهم، للحي الذي يجمع خمسة على الأقل.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'neighborhood' => Param::neighborhood(), 'property_type' => Param::propertyType(), 'days' => Param::days(180), 'limit' => Param::limit(20)];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'property_type' => 'النوع', 'clients' => 'العملاء', 'avg_budget_max' => 'متوسط أعلى الميزانية'];
    }

    public function query(array $params): array
    {
        return ['SELECT n.neighborhood, t.property_type, COUNT(*) AS clients, ROUND(AVG(k.budget_max)) AS avg_budget_max
            FROM ai_v_crm_clients k
            JOIN '.self::items('k.neighborhoods', 'neighborhood', 80).' n
            JOIN '.self::items('k.property_types', 'property_type', 12).' t
            WHERE k.city = ? AND k.created_at >= ? AND (? IS NULL OR n.neighborhood = ?) AND (? IS NULL OR t.property_type = ?)
            GROUP BY n.neighborhood, t.property_type HAVING COUNT(*) >= ? ORDER BY clients DESC LIMIT '.(int) $params['limit'],
            [$params['city'], self::since($params['days']), $params['neighborhood'], $params['neighborhood'], $params['property_type'], $params['property_type'], self::minimumGroup()]];
    }
}
