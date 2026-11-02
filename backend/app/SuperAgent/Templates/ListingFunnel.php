<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** مسار العروض: العروض التي أُنشئت في الفترة: كم نُشر، كم بانتظار الصياغة، كم بيع، كم أُخفي — حسب حالتها اليوم. */
final class ListingFunnel extends Template
{
    public function name(): string
    {
        return 'listing_funnel';
    }

    public function title(): string
    {
        return 'مسار العروض';
    }

    public function description(): string
    {
        return 'العروض التي أُنشئت في الفترة: كم نُشر، كم بانتظار الصياغة، كم بيع، كم أُخفي — حسب حالتها اليوم.';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['status' => 'الحالة', 'listings' => 'العروض'];
    }

    public function query(array $params): array
    {
        return ['SELECT status, COUNT(*) AS listings FROM ai_v_listings WHERE created_at >= ? GROUP BY status ORDER BY listings DESC', [self::since($params['days'])]];
    }
}
