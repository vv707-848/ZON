<?php

namespace App\SuperAgent;

use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use App\Models\ExecReport;
use App\Models\User;
use InvalidArgumentException;
use Throwable;

/**
 * «الوكيل الأعلى» (superadmin). A report is archived «running» (Archive) and finished by its job (answer):
 * - **a question:** report_select picks the approved templates that answer it, one for each question the message
 *   holds (three at most, none when no report answers it), with their parameters;
 * - **an exploration:** report_explore picks one template at a time from what the earlier ones found, four at
 *   most, and stops when the picture is clear;
 * - **the manual mode:** the staff's own template, with no model choosing.
 * Then: the parameters are checked (Param) · the SQL runs on the read-only views (ReportRunner, ai_reader) ·
 * report_write analyses all the rows and may attach a proposal to a recommendation (Proposal) · NumberGuard:
 * every number of the text must come from the rows.
 */
final class SuperAgent
{
    /** Questions one message may hold. */
    public const MAX_QUESTIONS = 3;

    /** Reports one exploration may run. */
    public const EXPLORE_STEPS = 4;

    /** Rows of each earlier step the explorer sees: enough to see what stands out. */
    private const EXPLORE_ROWS = 20;

    /** Rows report_write receives, all parts together (the agents' limit). */
    private const WRITE_ROWS = 200;

    public function __construct(private readonly AiGateway $ai, private readonly ReportRunner $runner, private readonly NumberGuard $guard) {}

    public function ask(User $staff, string $question): ExecReport
    {
        return Archive::open($staff, ExecReportMode::Question, $question);
    }

    /** $focus: what to look into («السوق في جدة»), or nothing for the whole platform. */
    public function explore(User $staff, string $focus): ExecReport
    {
        return Archive::open($staff, ExecReportMode::Explore, $focus);
    }

    /**
     * A template the staff chose. Its parameters are checked here: a value out of range is refused before anything runs.
     *
     * @param  array<string, mixed>  $params
     */
    public function manual(User $staff, Template $template, array $params, string $question, bool $analyse): ExecReport
    {
        return Archive::open($staff, ExecReportMode::Manual, $question, [['report' => $template->name(), 'params' => $template->checkParams($params)]], $analyse);
    }

    /** The job (RunExecReport): choose, run on the views, write, archive. */
    public function answer(ExecReport $report, bool $analyse): void
    {
        $parts = match ($report->mode) {
            ExecReportMode::Question => array_map($this->execute(...), $this->choose($report)),
            ExecReportMode::Explore => $this->exploreSteps($report),
            default => array_map($this->execute(...), $report->parts),
        };
        $ran = array_values(array_filter($parts, fn (array $part) => $part['status'] === 'ok'));
        if ($ran === []) {
            $report->update(['parts' => $parts, 'status' => ExecReportStatus::Failed]);

            return;
        }

        $hasRows = array_filter($ran, fn (array $part) => $part['rows'] !== []) !== [];
        $written = $analyse && $hasRows ? $this->write($report, $ran) : null;
        $report->update([
            'parts' => $parts,
            'status' => $written === null ? ExecReportStatus::NoAnalysis : ExecReportStatus::Ok,
            'analysis' => $written['analysis'] ?? null,
            'recommendations' => $written['recommendations'] ?? null,
        ]);
    }

    /** @return list<array{report: string, params: array<string, mixed>}> */
    private function choose(ExecReport $report): array
    {
        $choice = $this->ai->call(AiAgent::ReportSelect, ['question' => $report->question, 'catalog' => self::catalog()], check: function (array $out): ?array {
            $chosen = [];
            foreach ((array) ($out['reports'] ?? []) as $item) {
                $part = is_array($item) ? self::checked($item) : null;
                if ($part === null) {
                    return null;                    // a report outside the catalog or a parameter out of range: refused, not adjusted
                }
                if (! in_array($part, $chosen, true)) {
                    $chosen[] = $part;
                }
            }

            return count($chosen) > self::MAX_QUESTIONS ? null : ['reports' => $chosen];
        }, staff: $report->staff, cache: false);

        return $choice->usable() ? $choice->output['reports'] : [];
    }

    /** @return list<array{report: string, params: array<string, mixed>, rows: list<array<string, mixed>>, row_count: int, status: string}> */
    private function exploreSteps(ExecReport $report): array
    {
        $parts = [];
        for ($step = 0; $step < self::EXPLORE_STEPS; $step++) {
            $next = $this->ai->call(AiAgent::ReportExplore, [
                'focus' => $report->question,
                'catalog' => self::catalog(),
                'found' => array_map(fn (array $part) => [
                    'report' => $part['report'],
                    'params' => $part['params'],
                    'columns' => Catalog::find($part['report'])?->columns() ?? [],
                    'rows' => array_slice($part['rows'], 0, self::EXPLORE_ROWS),
                    'row_count' => $part['row_count'],
                ], $parts),
                'steps_left' => self::EXPLORE_STEPS - $step,
            ], check: fn (array $out): ?array => ($out['report'] ?? null) === null ? ['report' => null, 'params' => []] : self::checked($out),
                staff: $report->staff, cache: false);

            $choice = $next->usable() ? $next->output : null;
            $taken = array_map(fn (array $part) => ['report' => $part['report'], 'params' => $part['params']], $parts);
            if ($choice === null || $choice['report'] === null || in_array($choice, $taken, true)) {
                break;                              // unavailable, the picture is clear, or a step already taken
            }
            $parts[] = $this->execute($choice);
            $report->update(['parts' => $parts]);   // the page shows each table as it comes
        }

        return $parts;
    }

    /**
     * @param  array{report: string, params: array<string, mixed>}  $part
     * @return array{report: string, params: array<string, mixed>, rows: list<array<string, mixed>>, row_count: int, status: string}
     */
    private function execute(array $part): array
    {
        $failed = ['report' => $part['report'], 'params' => $part['params'], 'rows' => [], 'row_count' => 0, 'status' => 'failed'];
        $template = Catalog::find($part['report']);
        if ($template === null) {
            return $failed;
        }
        try {
            $rows = $this->runner->run($template, $template->checkParams($part['params']));
        } catch (Throwable $e) {
            report($e);

            return $failed;
        }

        return ['report' => $part['report'], 'params' => $part['params'], 'rows' => $rows, 'row_count' => count($rows), 'status' => 'ok'];
    }

    /**
     * @param  non-empty-list<array{report: string, params: array<string, mixed>, rows: list<array<string, mixed>>, row_count: int, status: string}>  $parts  the parts that ran
     * @return ?array{analysis: string, recommendations: list<array{text: string, action: ?string, params: array<string, mixed>}>}
     */
    private function write(ExecReport $report, array $parts): ?array
    {
        $share = intdiv(self::WRITE_ROWS, count($parts));
        $shown = array_map(fn (array $part) => [
            'report' => $part['report'],
            'params' => $part['params'],
            'columns' => Catalog::find($part['report'])?->columns() ?? [],
            'rows' => array_slice($part['rows'], 0, $share),
            'row_count' => $part['row_count'],
        ], $parts);
        $rows = array_merge(...array_column($shown, 'rows'));
        $params = array_merge(...array_map(fn (array $part) => array_values($part['params']), $shown));

        $written = $this->ai->call(AiAgent::ReportWrite, ['question' => self::asked($report), 'parts' => $shown, 'proposals' => Proposal::catalog()],
            check: function (array $out) use ($rows, $params): ?array {
                $analysis = is_string($out['analysis'] ?? null) ? trim($out['analysis']) : '';
                $recommendations = self::recommendations((array) ($out['recommendations'] ?? []));
                $text = $analysis.' '.implode(' ', array_column($recommendations, 'text'));

                return $analysis === '' || ! $this->guard->passes($text, $rows, $params) ? null : ['analysis' => $analysis, 'recommendations' => $recommendations];
            }, staff: $report->staff, cache: false);

        return $written->usable() ? $written->output : null;
    }

    /**
     * The recommendations kept: each text, and its proposal only when it is in the catalog with its parameters in
     * range — otherwise the recommendation stays without a button.
     *
     * @param  array<mixed>  $given
     * @return list<array{text: string, action: ?string, params: array<string, mixed>}>
     */
    private static function recommendations(array $given): array
    {
        $kept = [];
        foreach ($given as $item) {
            $text = is_array($item) && is_string($item['text'] ?? null) ? trim($item['text']) : '';
            if ($text === '') {
                continue;
            }
            $proposal = is_string($item['action'] ?? null) ? Proposal::tryFrom($item['action']) : null;
            try {
                $params = $proposal?->checkParams(is_array($item['params'] ?? null) ? $item['params'] : []) ?? [];
            } catch (InvalidArgumentException) {
                [$proposal, $params] = [null, []];
            }
            $kept[] = ['text' => $text, 'action' => $proposal?->value, 'params' => $params];
        }

        return $kept;
    }

    /**
     * A model's choice checked against the catalog: null when the report is unknown or a parameter is refused.
     *
     * @param  array<mixed>  $choice
     * @return ?array{report: string, params: array<string, mixed>}
     */
    private static function checked(array $choice): ?array
    {
        $template = is_string($choice['report'] ?? null) ? Catalog::find($choice['report']) : null;
        try {
            return $template === null ? null : ['report' => $template->name(), 'params' => $template->checkParams(is_array($choice['params'] ?? null) ? $choice['params'] : [])];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> the catalog report_select and report_explore receive */
    public static function catalog(): array
    {
        return array_values(array_map(fn (Template $t) => $t->catalogEntry(), Catalog::all()));
    }

    /** What report_write is told was asked. */
    private static function asked(ExecReport $report): string
    {
        if ($report->mode !== ExecReportMode::Explore) {
            return $report->question;
        }

        return ($report->question === '' ? 'استكشاف عام' : 'استكشاف: '.$report->question).' — ما الذي يستحق انتباه الإدارة في هذه البيانات؟';
    }
}
