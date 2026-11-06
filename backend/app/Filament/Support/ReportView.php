<?php

namespace App\Filament\Support;

use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use App\Models\ExecReport;
use App\SuperAgent\Catalog;
use App\SuperAgent\NotesDigest;
use App\SuperAgent\Proposal;
use Illuminate\Support\HtmlString;

/**
 * An archived report as HTML: how it was asked and where it stands, the analysis (when it passed NumberGuard) with
 * a button under each recommendation that has a proposal, then each part — its template, parameters and rows.
 * While the report runs, the page polls (wire:poll) until its job finishes. Styles are inline: the panel's
 * compiled CSS only holds the classes Filament itself uses.
 */
final class ReportView
{
    private const TABLE = 'width:100%;border-collapse:collapse;font-size:13px';

    private const CELL = 'border:1px solid #e5e7eb;padding:6px 10px;text-align:start';

    private const HEAD = 'border:1px solid #e5e7eb;padding:6px 10px;text-align:start;background:#f3f4f6;font-weight:600;white-space:nowrap';

    private const BUTTON = 'display:inline-block;margin-inline-start:8px;padding:1px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;white-space:nowrap';

    /** Reports that are not a template's: their title, columns and parameter labels. */
    private const OWN = [
        NotesDigest::REPORT => ['title' => 'قراءة ملاحظات العملاء', 'columns' => ['theme' => 'الموضوع', 'share' => 'الحضور'],
            'labels' => ['city' => 'المدينة', 'neighborhood' => 'الحي', 'property_type' => 'النوع', 'category' => 'التصنيف', 'days' => 'الأيام', 'notes' => 'الملاحظات المقروءة']],
    ];

    private const SHARES = ['high' => 'كثير', 'medium' => 'متوسط', 'low' => 'قليل'];

    public static function render(ExecReport $report): HtmlString
    {
        $status = $report->shownStatus();
        $parts = $report->parts;
        $titled = $report->mode === ExecReportMode::Explore || count($parts) > 1;   // otherwise the heading names the one report
        $heading = match (true) {
            ! $titled && $parts !== [] => self::title($parts[0]['report']),
            $report->question !== '' => $report->question,
            default => 'المنصة كلها',
        };

        $html = '<div style="display:flex;flex-direction:column;gap:14px;font-size:14px">';
        $html .= '<p><strong>'.e($heading).'</strong> · '.e($report->mode->label()).' · '.e($status->label()).'</p>';
        if ($status === ExecReportStatus::Running) {
            $html .= '<p wire:poll.2s>جارٍ إعداد التقرير… تظهر النتائج هنا حين تكتمل.</p>';
        } elseif ($status === ExecReportStatus::Failed && $parts === []) {
            $html .= '<p>لم يُفهم الطلب ضمن التقارير المعتمدة، أو تعذّر الوصول إلى الذكاء. جرّب صياغة أخرى أو الوضع اليدوي.</p>';
        }
        if ($report->analysis) {
            $html .= '<p style="line-height:1.8">'.nl2br(e($report->analysis)).'</p>'.self::recommendations((array) $report->recommendations);
        }
        foreach ($parts as $part) {
            $html .= self::part($part, $titled);
        }

        return new HtmlString($html.'</div>');
    }

    /** @param array<mixed> $recommendations */
    private static function recommendations(array $recommendations): string
    {
        $items = '';
        foreach ($recommendations as $recommendation) {
            if (! is_array($recommendation) || ! is_string($recommendation['text'] ?? null)) {
                continue;
            }
            $proposal = is_string($recommendation['action'] ?? null) ? Proposal::tryFrom($recommendation['action']) : null;
            $url = $proposal?->url(is_array($recommendation['params'] ?? null) ? $recommendation['params'] : []);
            $items .= '<li>'.e($recommendation['text']).($proposal === null || $url === null ? '' : '<a href="'.e($url).'" style="'.self::BUTTON.'">'.e($proposal->title()).'</a>').'</li>';
        }

        return $items === '' ? '' : '<ul style="list-style:disc;padding-inline-start:20px;display:flex;flex-direction:column;gap:6px">'.$items.'</ul>';
    }

    /** @param array{report: string, params: array<string, mixed>, rows: list<array<string, mixed>>, row_count: int, status: string} $part */
    private static function part(array $part, bool $titled): string
    {
        $template = Catalog::find($part['report']);
        $own = self::OWN[$part['report']] ?? null;
        $first = array_keys($part['rows'][0] ?? []);
        $columns = $template?->columns() ?? $own['columns'] ?? array_combine($first, $first);
        $declared = $template === null ? [] : array_intersect_key(array_flip(array_keys($template->params())), $part['params']);
        $params = collect(array_replace($declared, $part['params']))->reject(fn ($value) => $value === null || $value === '')   // MySQL sorts a JSON object's keys
            ->map(fn ($value, $name) => e(($template?->params()[$name]->label ?? $own['labels'][$name] ?? $name).': '.self::value($value)))->implode(' · ');

        $html = '<div style="display:flex;flex-direction:column;gap:6px">';
        $line = ($titled ? '<strong>'.e(self::title($part['report'])).'</strong>' : '').($titled && $params !== '' ? ' · ' : '').$params;
        if ($line !== '') {
            $html .= '<p>'.$line.'</p>';
        }
        if ($part['status'] === 'failed') {
            $html .= '<p>تعذّر تشغيل هذا التقرير.</p>';
        } elseif ($part['rows'] !== []) {
            $html .= '<div style="overflow-x:auto"><table style="'.self::TABLE.'"><thead><tr>'
                .collect($columns)->map(fn ($label) => '<th style="'.self::HEAD.'">'.e($label).'</th>')->implode('').'</tr></thead><tbody>';
            foreach ($part['rows'] as $row) {
                $html .= '<tr>'.collect(array_keys($columns))->map(fn ($key) => '<td style="'.self::CELL.'">'.e($key === 'share' ? (self::SHARES[$row[$key] ?? ''] ?? '—') : self::value($row[$key] ?? null)).'</td>')->implode('').'</tr>';
            }
            $html .= '</tbody></table></div>';
        } elseif ($part['status'] === 'ok') {
            $html .= '<p>لا توجد بيانات كافية لهذا التقرير.</p>';
        }

        return $html.'</div>';
    }

    private static function title(string $report): string
    {
        return Catalog::find($report)?->title() ?? self::OWN[$report]['title'] ?? $report;
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
