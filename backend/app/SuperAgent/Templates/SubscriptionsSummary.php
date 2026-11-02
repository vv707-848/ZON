<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** ملخص الاشتراكات: الاشتراكات السارية الآن لكل باقة ومصدر، والجديد منها في الفترة. */
final class SubscriptionsSummary extends Template
{
    public function name(): string
    {
        return 'subscriptions_summary';
    }

    public function title(): string
    {
        return 'ملخص الاشتراكات';
    }

    public function description(): string
    {
        return 'الاشتراكات السارية الآن لكل باقة ومصدر، والجديد منها في الفترة.';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['package_code' => 'الباقة', 'source' => 'المصدر', 'active' => 'سارية', 'new' => 'جديدة في الفترة'];
    }

    public function query(array $params): array
    {
        return ['SELECT package_code, source, SUM(cancelled_at IS NULL AND starts_at <= NOW() AND (ends_at IS NULL OR ends_at > NOW())) AS active, SUM(starts_at >= ?) AS new
            FROM ai_v_subscriptions GROUP BY package_code, source ORDER BY active DESC', [self::since($params['days'])]];
    }
}
