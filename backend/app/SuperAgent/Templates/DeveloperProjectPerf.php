<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** أداء مشاريع المطوّرين: لكل مشروع منشور: المشاهدات، والنماذج، والوحدات المتاحة، وأقل سعر. */
final class DeveloperProjectPerf extends Template
{
    public function name(): string
    {
        return 'developer_project_perf';
    }

    public function title(): string
    {
        return 'أداء مشاريع المطوّرين';
    }

    public function description(): string
    {
        return 'لكل مشروع منشور: المشاهدات، والنماذج، والوحدات المتاحة، وأقل سعر.';
    }

    public function params(): array
    {
        return ['city' => Param::city(required: false), 'limit' => Param::limit(20)];
    }

    public function columns(): array
    {
        return ['project_id' => 'المشروع', 'neighborhood' => 'الحي', 'views' => 'المشاهدات', 'models' => 'النماذج', 'available' => 'المتاح', 'start_price' => 'يبدأ من'];
    }

    public function query(array $params): array
    {
        return ["SELECT p.id AS project_id, p.neighborhood, p.views_count AS views, COUNT(u.id) AS models, COALESCE(SUM(u.available_count), 0) AS available,
                MIN(CASE WHEN u.available_count > 0 THEN u.price END) AS start_price
            FROM ai_v_projects p LEFT JOIN ai_v_project_units u ON u.project_id = p.id
            WHERE p.status = 'published' AND (? IS NULL OR p.city = ?) GROUP BY p.id, p.neighborhood, p.views_count ORDER BY views DESC LIMIT ?",
            [$params['city'], $params['city'], $params['limit']]];
    }
}
