<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** أكثر الأحياء طلباً: عدد طلبات البحث في كل حي من أحياء المدينة خلال الفترة، والعاجل منها. */
final class TopDemandByNeighborhood extends Template
{
    public function name(): string
    {
        return 'top_demand_by_neighborhood';
    }

    public function title(): string
    {
        return 'أكثر الأحياء طلباً';
    }

    public function description(): string
    {
        return 'عدد طلبات البحث في كل حي من أحياء المدينة خلال الفترة، والعاجل منها.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'days' => Param::days(), 'limit' => Param::limit()];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'requests' => 'الطلبات', 'urgent' => 'العاجل', 'avg_budget_max' => 'متوسط أعلى ميزانية'];
    }

    public function query(array $params): array
    {
        return ['SELECT n.neighborhood, COUNT(DISTINCT r.id) AS requests, COUNT(DISTINCT CASE WHEN r.urgent THEN r.id END) AS urgent, ROUND(AVG(r.budget_max)) AS avg_budget_max
            FROM ai_v_requests r JOIN '.self::items('r.neighborhoods', 'neighborhood', 80).' n
            WHERE r.city = ? AND r.created_at >= ? GROUP BY n.neighborhood ORDER BY requests DESC LIMIT ?', [$params['city'], self::since($params['days']), $params['limit']]];
    }
}
