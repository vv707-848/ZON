<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** أكثر أنواع العقار طلباً: عدد الطلبات لكل نوع عقار خلال الفترة، في مدينة أو في كل المدن. */
final class TopPropertyTypes extends Template
{
    public function name(): string
    {
        return 'top_property_types';
    }

    public function title(): string
    {
        return 'أكثر أنواع العقار طلباً';
    }

    public function description(): string
    {
        return 'عدد الطلبات لكل نوع عقار خلال الفترة، في مدينة أو في كل المدن.';
    }

    public function params(): array
    {
        return ['city' => Param::city(required: false), 'days' => Param::days()];
    }

    public function columns(): array
    {
        return ['property_type' => 'النوع', 'requests' => 'الطلبات'];
    }

    public function query(array $params): array
    {
        return ['SELECT t.property_type, COUNT(DISTINCT r.id) AS requests
            FROM ai_v_requests r JOIN '.self::items('r.property_types', 'property_type', 12).' t
            WHERE r.created_at >= ? AND (? IS NULL OR r.city = ?) GROUP BY t.property_type ORDER BY requests DESC', [self::since($params['days']), $params['city'], $params['city']]];
    }
}
