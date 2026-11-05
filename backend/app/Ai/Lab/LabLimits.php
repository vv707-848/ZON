<?php

namespace App\Ai\Lab;

/**
 * The limits a model must meet before its overall score counts (docs/model-lab.md «قراءة النتيجة»): the highest
 * score wins only among the runs that meet them all.
 */
final class LabLimits
{
    /** p95 of one search or matching answer the user still does not feel (the hard limit is the 15 s budget). */
    public const SEARCH_P95_MS = 6000;

    /**
     * @param  array<string, array<string, mixed>>  $agents  the report's `agents`
     * @return list<string>
     */
    public static function unmet(array $agents): array
    {
        $metric = fn (string $agent, string $name): ?float => is_numeric($agents[$agent]['metrics'][$name] ?? null)
            ? (float) $agents[$agent]['metrics'][$name] : null;
        $unmet = [];

        $failing = array_keys(array_filter($agents, fn (array $entry) => (int) ($entry['failed'] ?? 0) > 0));
        if ($failing !== []) {
            $unmet[] = 'ردود فاشلة في: '.implode('، ', $failing);
        }
        foreach (['search_listings', 'search_requests'] as $agent) {
            if (($metric($agent, 'inject_top3') ?? 0.0) > 0) {
                $unmet[] = "{$agent}: سطر محقون في إعلان أو طلب وصل إلى الثلاثة الأولى";
            }
        }
        foreach (['search_listings' => 'zero_top3', 'matching' => 'zero_top3_matched'] as $agent => $name) {
            if (($metric($agent, $name) ?? 0.0) > 0.05) {
                $unmet[] = "{$agent}: عرض يكسر الميزانية أو الغرف في الثلاثة الأولى بأكثر من 5% من الحالات";
            }
            $p95 = $agents[$agent]['latency_ms']['p95'] ?? null;
            if (is_numeric($p95) && (int) $p95 > self::SEARCH_P95_MS) {
                $unmet[] = "{$agent}: p95 ".number_format((int) $p95).' ms فوق '.number_format(self::SEARCH_P95_MS);
            }
            $stability = $agents[$agent]['variants']['reversed']['stability'] ?? null;
            if (is_numeric($stability) && (float) $stability < 0.8) {
                $unmet[] = "{$agent}: يتغير ترتيبه إذا انعكس ترتيب المرشحين (الثبات ".round((float) $stability * 100).'%)';
            }
        }
        if (($metric('clean_listing', 'invented') ?? 1.0) < 0.95) {
            $unmet[] = 'clean_listing: أضاف ما لم يقله المعلن في أكثر من 5% من الإعلانات';
        }
        if (($metric('report_write', 'guard') ?? 1.0) < 0.9) {
            $unmet[] = 'report_write: NumberGuard رفض أكثر من 10% من التحليلات';
        }

        return $unmet;
    }
}
