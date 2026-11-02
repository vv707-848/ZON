<?php

namespace App\SuperAgent;

use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Models\ExecReport;
use App\Models\User;
use App\Support\Trace;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * «الوكيل الأعلى» (superadmin): a question in Arabic →
 *   1 report_select picks an approved template and its parameters from the catalog ·
 *   2 the parameters are checked (Param) · 3 the SQL runs on the read-only views (ReportRunner, ai_reader) ·
 *   4 report_write analyses the rows · 5 NumberGuard: every number must come from the rows · 6 archived.
 * The manual mode runs a chosen template without any model call.
 */
final class SuperAgent
{
    public function __construct(private readonly AiGateway $ai, private readonly ReportRunner $runner, private readonly NumberGuard $guard) {}

    public function ask(User $staff, string $question): ExecReport
    {
        $this->assertCap($staff);
        $catalog = array_values(array_map(fn (Template $t) => $t->catalogEntry(), Catalog::all()));

        $choice = $this->ai->call(AiAgent::ReportSelect, ['question' => $question, 'catalog' => $catalog], check: function (array $out): ?array {
            $template = Catalog::find((string) ($out['report'] ?? ''));
            try {
                return $template === null ? null : ['report' => $template->name(), 'params' => $template->checkParams((array) ($out['params'] ?? []))];
            } catch (InvalidArgumentException) {
                return null;                                            // a parameter outside the declared range: refused, not adjusted
            }
        }, staff: $staff, cache: false);

        if (! $choice->usable()) {
            return $this->archive($staff, $question, '—', [], [], 'failed', null, null);
        }

        return $this->run($staff, Catalog::find($choice->output['report']), $choice->output['params'], $question, analyse: true);
    }

    /** @param array<string, mixed> $params */
    public function run(User $staff, Template $template, array $params, string $question, bool $analyse): ExecReport
    {
        $this->assertCap($staff);
        $params = $template->checkParams($params);
        try {
            $rows = $this->runner->run($template, $params);
        } catch (Throwable $e) {
            report($e);

            return $this->archive($staff, $question, $template->name(), $params, [], 'failed', null, null);
        }
        if (! $analyse || $rows === []) {
            return $this->archive($staff, $question, $template->name(), $params, $rows, 'no_analysis', null, null);
        }

        $written = $this->ai->call(AiAgent::ReportWrite, [
            'question' => $question,
            'report' => $template->name(),
            'columns' => $template->columns(),
            'rows' => $rows,
        ], check: function (array $out) use ($rows, $params): ?array {
            $analysis = trim((string) ($out['analysis'] ?? ''));
            $recommendations = array_values(array_filter((array) ($out['recommendations'] ?? []), 'is_string'));
            $text = $analysis.' '.implode(' ', $recommendations);

            return $analysis === '' || ! $this->guard->passes($text, $rows, $params) ? null : ['analysis' => $analysis, 'recommendations' => $recommendations];
        }, staff: $staff, cache: false);

        return $written->usable()
            ? $this->archive($staff, $question, $template->name(), $params, $rows, 'ok', $written->output['analysis'], $written->output['recommendations'])
            : $this->archive($staff, $question, $template->name(), $params, $rows, 'no_analysis', null, null);
    }

    private function assertCap(User $staff): void
    {
        if (DailyCap::left($staff) < 1) {
            throw new RuntimeException('بلغت سقف التقارير اليومي.');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  list<array<string, mixed>>  $rows
     * @param  ?list<string>  $recommendations
     */
    private function archive(User $staff, string $question, string $report, array $params, array $rows, string $status, ?string $analysis, ?array $recommendations): ExecReport
    {
        return ExecReport::query()->create([
            'staff_id' => $staff->id,
            'question' => mb_substr($question, 0, 500),
            'report' => $report,
            'params' => $params,
            'rows' => $rows,
            'row_count' => count($rows),
            'analysis' => $analysis,
            'recommendations' => $recommendations,
            'status' => $status,
            'trace_id' => Trace::id(),
            'created_at' => now(),
        ]);
    }
}
