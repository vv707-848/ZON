<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** ملخص الإيراد: المبالغ المدفوعة في الفترة حسب الغرض بالريال، وعدد الدفعات والفاشلة. */
final class RevenueSummary extends Template
{
    public function name(): string
    {
        return 'revenue_summary';
    }

    public function title(): string
    {
        return 'ملخص الإيراد';
    }

    public function description(): string
    {
        return 'المبالغ المدفوعة في الفترة حسب الغرض بالريال، وعدد الدفعات والفاشلة.';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['purpose' => 'الغرض', 'payments' => 'الدفعات', 'revenue_sar' => 'الإيراد (ريال)', 'failed' => 'الفاشلة'];
    }

    public function query(array $params): array
    {
        $since = self::since($params['days']);

        return ["SELECT purpose, SUM(status IN ('paid', 'unapplied') AND paid_at >= ?) AS payments,
                ROUND(SUM(CASE WHEN status IN ('paid', 'unapplied') AND paid_at >= ? THEN amount_halalas ELSE 0 END) / 100, 2) AS revenue_sar,
                SUM(status = 'failed' AND created_at >= ?) AS failed
            FROM ai_v_payments GROUP BY purpose ORDER BY revenue_sar DESC", [$since, $since, $since]];
    }
}
