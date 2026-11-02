<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** مسار المستخدم: من الحسابات الجديدة في الفترة: كم سجّل رغبة، وكم نشر طلباً أو عرضاً، وكم دفع. */
final class UserFunnel extends Template
{
    public function name(): string
    {
        return 'user_funnel';
    }

    public function title(): string
    {
        return 'مسار المستخدم';
    }

    public function description(): string
    {
        return 'من الحسابات الجديدة في الفترة: كم سجّل رغبة، وكم نشر طلباً أو عرضاً، وكم دفع.';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['role' => 'الدور', 'signed_up' => 'سجّل', 'with_wish' => 'برغبة', 'posted' => 'نشر طلباً أو عرضاً', 'paid' => 'دفع'];
    }

    public function query(array $params): array
    {
        return ['SELECT role, COUNT(*) AS signed_up, SUM(has_preference) AS with_wish, SUM(has_listing OR has_request) AS posted, SUM(has_paid) AS paid
            FROM ai_v_customers WHERE created_at >= ? GROUP BY role', [self::since($params['days'])]];
    }
}
