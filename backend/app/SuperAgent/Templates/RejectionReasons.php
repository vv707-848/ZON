<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\MarketTemplate;
use App\SuperAgent\Param;

/** نسبة «غير مطابق» (تقرير سوق): نسبة الردود «غير مطابق» لكل حي وخدمة خلال الفترة — الأحياء ذات الردود الكافية فقط. */
final class RejectionReasons extends MarketTemplate
{
    public function name(): string
    {
        return 'rejection_reasons';
    }

    public function title(): string
    {
        return 'نسبة «غير مطابق» (تقرير سوق)';
    }

    public function description(): string
    {
        return 'نسبة الردود «غير مطابق» لكل حي وخدمة خلال الفترة — الأحياء ذات الردود الكافية فقط.';
    }

    public function params(): array
    {
        return ['city' => Param::city(), 'days' => Param::days()];
    }

    public function columns(): array
    {
        return ['neighborhood' => 'الحي', 'context' => 'الخدمة', 'answers' => 'الردود', 'rejection_rate' => 'نسبة غير مطابق'];
    }

    public function query(array $params): array
    {
        return ['SELECT neighborhood, context, COUNT(*) AS answers, ROUND(AVG(is_match = 0), 3) AS rejection_rate FROM ai_v_search_feedback
            WHERE city = ? AND created_at >= ? AND neighborhood IS NOT NULL GROUP BY neighborhood, context HAVING answers >= ? ORDER BY rejection_rate DESC',
            [$params['city'], self::since($params['days']), self::minimumGroup()]];
    }
}
