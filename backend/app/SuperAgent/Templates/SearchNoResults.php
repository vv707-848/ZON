<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** بحث بلا نتيجة: أكثر ما بحث عنه الناس ولم يجدوا له أي عرض — طلب غير مغطى. */
final class SearchNoResults extends Template
{
    public function name(): string
    {
        return 'search_no_results';
    }

    public function title(): string
    {
        return 'بحث بلا نتيجة';
    }

    public function description(): string
    {
        return 'أكثر ما بحث عنه الناس ولم يجدوا له أي عرض — طلب غير مغطى.';
    }

    public function params(): array
    {
        return ['days' => Param::days(), 'limit' => Param::limit()];
    }

    public function columns(): array
    {
        return ['city' => 'المدينة', 'neighborhood' => 'الحي', 'property_type' => 'النوع', 'misses' => 'مرات'];
    }

    public function query(array $params): array
    {
        return ['SELECT city, neighborhood, property_type, COUNT(*) AS misses FROM ai_v_search_misses WHERE created_at >= ?
            GROUP BY city, neighborhood, property_type ORDER BY misses DESC LIMIT ?', [self::since($params['days']), $params['limit']]];
    }
}
