<?php

namespace App\Filament\Support;

use App\Models\ExecReport;
use App\SuperAgent\Catalog;
use Illuminate\Support\HtmlString;

/**
 * An archived report as HTML: the template, its parameters, the analysis (when it passed NumberGuard), the rows.
 * Styles are inline: the panel's compiled CSS only holds the classes Filament itself uses.
 */
final class ReportView
{
    private const TABLE = 'width:100%;border-collapse:collapse;font-size:13px';

    private const CELL = 'border:1px solid #e5e7eb;padding:6px 10px;text-align:start';

    private const HEAD = 'border:1px solid #e5e7eb;padding:6px 10px;text-align:start;background:#f3f4f6;font-weight:600;white-space:nowrap';

    public static function render(ExecReport $report): HtmlString
    {
        $template = Catalog::find($report->report);
        $columns = $template?->columns() ?? array_combine(array_keys($report->rows[0] ?? []), array_keys($report->rows[0] ?? []));
        $status = ['ok' => 'تحليل موثّق', 'no_analysis' => 'بيانات بلا تحليل', 'failed' => 'تعذّر التقرير'][$report->status] ?? $report->status;
        $params = collect($report->params)->map(fn ($value, $name) => e(($template?->params()[$name]->label ?? $name).': '.$value))->implode(' · ');

        $html = '<div style="display:flex;flex-direction:column;gap:12px;font-size:14px">';
        $html .= '<p><strong>'.e($template?->title() ?? $report->report).'</strong> · '.e($status).($params === '' ? '' : ' · '.$params).'</p>';
        if ($report->analysis) {
            $html .= '<p style="line-height:1.8">'.nl2br(e($report->analysis)).'</p>';
            if ($report->recommendations) {
                $html .= '<ul style="list-style:disc;padding-inline-start:20px">'.collect($report->recommendations)->map(fn ($r) => '<li>'.e($r).'</li>')->implode('').'</ul>';
            }
        }
        if ($report->rows !== []) {
            $html .= '<div style="overflow-x:auto"><table style="'.self::TABLE.'"><thead><tr>'
                .collect($columns)->map(fn ($label) => '<th style="'.self::HEAD.'">'.e($label).'</th>')->implode('').'</tr></thead><tbody>';
            foreach ($report->rows as $row) {
                $html .= '<tr>'.collect(array_keys($columns))->map(fn ($key) => '<td style="'.self::CELL.'">'.e(self::value($row[$key] ?? null)).'</td>')->implode('').'</tr>';
            }
            $html .= '</tbody></table></div>';
        } elseif ($report->status !== 'failed') {
            $html .= '<p>لا توجد بيانات كافية لهذا التقرير.</p>';
        }

        return new HtmlString($html.'</div>');
    }

    /** Numbers with thousands separators (1,933,333 · 0.42), the rest as written. */
    private static function value(mixed $value): string
    {
        return match (true) {
            is_int($value) => number_format($value),
            is_float($value) => rtrim(rtrim(number_format($value, 2), '0'), '.'),
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }
}
