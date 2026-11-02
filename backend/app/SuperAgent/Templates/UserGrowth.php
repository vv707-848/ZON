<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** نمو المستخدمين: الحسابات الجديدة في الفترة حسب الدور، ومجموع الحسابات النشطة. */
final class UserGrowth extends Template
{
    public function name(): string
    {
        return 'user_growth';
    }

    public function title(): string
    {
        return 'نمو المستخدمين';
    }

    public function description(): string
    {
        return 'الحسابات الجديدة في الفترة حسب الدور، ومجموع الحسابات النشطة.';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['role' => 'الدور', 'new_accounts' => 'جديد في الفترة', 'total_active' => 'كل الحسابات النشطة'];
    }

    public function query(array $params): array
    {
        return ["SELECT role, SUM(created_at >= ?) AS new_accounts, SUM(status = 'active') AS total_active FROM ai_v_customers GROUP BY role ORDER BY new_accounts DESC", [self::since($params['days'])]];
    }
}
