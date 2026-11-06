<?php

namespace App\Filament\Support;

use App\Models\ModelEvaluation;
use Illuminate\Support\HtmlString;

/**
 * A model lab run as HTML: the limits, each agent's score and metrics, and the cases it got wrong with its answer.
 * Inline styles, like ReportView: the panel's compiled CSS holds only Filament's own classes.
 */
final class LabView
{
    private const TABLE = 'width:100%;border-collapse:collapse;font-size:13px';

    private const CELL = 'border:1px solid #e5e7eb;padding:6px 10px;text-align:start;vertical-align:top';

    private const HEAD = 'border:1px solid #e5e7eb;padding:6px 10px;text-align:start;background:#f3f4f6;font-weight:600;white-space:nowrap';

    public const AGENTS = [
        'matching' => 'مساعد المطابقة', 'search_listings' => 'البحث في العروض', 'search_requests' => 'البحث في الطلبات',
        'match_searchers' => 'باحثو المطوّر', 'clean_listing' => 'تنقية الإعلان', 'chat' => 'الشات', 'summarize' => 'تلخيص الملاحظات',
        'report_select' => 'اختيار التقرير', 'report_write' => 'تحليل التقرير',
    ];

    /** The worst answers shown on the page; the rest are in the report file. */
    private const FAILURES_SHOWN = 60;

    public static function limits(ModelEvaluation $run): HtmlString
    {
        $unmet = $run->unmetLimits();
        if ($unmet === []) {
            return new HtmlString('<p style="color:#047857;font-weight:600">✓ يجتاز كل الحدود — درجته الإجمالية تُقارن بغيره.</p>');
        }

        return new HtmlString('<ul style="list-style:disc;padding-inline-start:20px;color:#b91c1c">'
            .collect($unmet)->map(fn (string $line) => '<li>'.e($line).'</li>')->implode('').'</ul>');
    }

    public static function agents(ModelEvaluation $run): HtmlString
    {
        $html = '<div style="overflow-x:auto"><table style="'.self::TABLE.'"><thead><tr>';
        foreach (['الوكيل', 'الحالات', 'الدرجة', 'فاشلة', 'p50 ms', 'p95 ms', 'المقاييس'] as $head) {
            $html .= '<th style="'.self::HEAD.'">'.e($head).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($run->agents as $agent => $entry) {
            $metrics = collect((array) ($entry['metrics'] ?? []))->map(fn ($value, $name) => e($name).' '.self::number($value));
            foreach ($entry['variants'] ?? [] as $variant => $result) {
                $metrics->push(e($variant).' '.self::number($result['score'] ?? null)
                    .(isset($result['stability']) ? ' · stability '.self::number($result['stability']) : ''));
            }
            $html .= '<tr><td style="'.self::CELL.'">'.e(self::AGENTS[$agent] ?? $agent).'<br><code style="color:#6b7280">'.e($agent).'</code></td>'
                .'<td style="'.self::CELL.'">'.(int) ($entry['cases'] ?? 0).'</td>'
                .'<td style="'.self::CELL.';font-weight:600">'.self::number($entry['score'] ?? null).'</td>'
                .'<td style="'.self::CELL.'">'.(int) ($entry['failed'] ?? 0).'</td>'
                .'<td style="'.self::CELL.'">'.number_format((int) ($entry['latency_ms']['p50'] ?? 0)).'</td>'
                .'<td style="'.self::CELL.'">'.number_format((int) ($entry['latency_ms']['p95'] ?? 0)).'</td>'
                .'<td style="'.self::CELL.'">'.$metrics->implode(' · ').'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table></div>');
    }

    /** Failed calls first, then the lowest scores: what to read before trusting a number. */
    public static function failures(ModelEvaluation $run): HtmlString
    {
        $wrong = collect((array) ($run->report['outcomes'] ?? []))->filter(fn (array $o) => ($o['error'] ?? null) !== null || (float) ($o['score'] ?? 0) < 0.5)
            ->sortBy(fn (array $o) => [($o['error'] ?? null) === null ? 1 : 0, (float) ($o['score'] ?? 0)])->values();
        if ($wrong->isEmpty()) {
            return new HtmlString('<p>لا حالة دون النصف.</p>');
        }
        $html = '<p style="margin-bottom:8px">'.number_format($wrong->count()).' حالة دون النصف أو بلا جواب'
            .($wrong->count() > self::FAILURES_SHOWN ? ' — أسوأ '.self::FAILURES_SHOWN.' هنا، والباقي في ملف التقرير' : '').'.</p>';
        $html .= '<div style="overflow-x:auto"><table style="'.self::TABLE.'"><thead><tr>';
        foreach (['الحالة', 'الوكيل', 'النسخة', 'الدرجة', 'الخطأ أو المقاييس', 'جواب النموذج'] as $head) {
            $html .= '<th style="'.self::HEAD.'">'.e($head).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($wrong->take(self::FAILURES_SHOWN) as $o) {
            $detail = ($o['error'] ?? null) ?? collect((array) ($o['metrics'] ?? []))->map(fn ($v, $k) => "{$k} ".self::number($v))->implode(' · ');
            $answer = json_encode($o['output'] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '—';
            $html .= '<tr><td style="'.self::CELL.'"><code>'.e((string) ($o['case'] ?? '')).'</code></td>'
                .'<td style="'.self::CELL.'">'.e(self::AGENTS[$o['agent'] ?? ''] ?? (string) ($o['agent'] ?? '')).'</td>'
                .'<td style="'.self::CELL.'">'.e((string) ($o['variant'] ?? '')).'</td>'
                .'<td style="'.self::CELL.'">'.self::number($o['score'] ?? null).'</td>'
                .'<td style="'.self::CELL.'">'.e($detail).'</td>'
                .'<td style="'.self::CELL.'"><pre style="white-space:pre-wrap;max-height:220px;overflow:auto;font-size:12px">'.e(mb_substr($answer, 0, 1500)).'</pre></td></tr>';
        }

        return new HtmlString($html.'</tbody></table></div>');
    }

    private static function number(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 3), '0'), '.') : '—';
    }
}
