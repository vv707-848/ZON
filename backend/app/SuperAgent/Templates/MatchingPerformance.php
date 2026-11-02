<?php

namespace App\SuperAgent\Templates;

use App\SuperAgent\Param;
use App\SuperAgent\Template;

/** أداء المطابقة: نداءات المطابقة والبحث في الفترة: نسبة الردود الكاملة، والبديل، ومتوسط الزمن، ونسبة «مطابق». */
final class MatchingPerformance extends Template
{
    public function name(): string
    {
        return 'matching_performance';
    }

    public function title(): string
    {
        return 'أداء المطابقة';
    }

    public function description(): string
    {
        return 'نداءات المطابقة والبحث في الفترة: نسبة الردود الكاملة، والبديل، ومتوسط الزمن، ونسبة «مطابق».';
    }

    public function params(): array
    {
        return ['days' => Param::days()];
    }

    public function columns(): array
    {
        return ['agent' => 'الوكيل', 'calls' => 'النداءات', 'full_rate' => 'نسبة الرد الكامل', 'fallback_rate' => 'نسبة البديل', 'avg_latency_ms' => 'متوسط الزمن (ms)', 'match_rate' => 'نسبة مطابق'];
    }

    public function query(array $params): array
    {
        $since = self::since($params['days']);

        return ["SELECT a.agent, COUNT(*) AS calls, ROUND(AVG(a.status IN ('ok', 'cached')), 3) AS full_rate, ROUND(AVG(a.status IN ('fallback', 'timeout')), 3) AS fallback_rate,
                ROUND(AVG(CASE WHEN a.status = 'ok' THEN a.latency_ms END)) AS avg_latency_ms,
                (SELECT ROUND(AVG(f.is_match), 3) FROM ai_v_search_feedback f WHERE f.created_at >= ?) AS match_rate
            FROM ai_v_ai_requests a WHERE a.created_at >= ? AND a.agent IN ('matching', 'search_listings', 'search_requests', 'match_searchers') GROUP BY a.agent", [$since, $since]];
    }
}
