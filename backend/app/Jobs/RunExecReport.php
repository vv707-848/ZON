<?php

namespace App\Jobs;

use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use App\Models\ExecReport;
use App\SuperAgent\NotesDigest;
use App\SuperAgent\SuperAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * A SuperAgent report, finished in the background (queue `ai`). An exploration makes five AI calls and a notes
 * digest up to eleven, each up to 20 s; nginx ends a panel request at 30 s. The page polls the archived report.
 */
class RunExecReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Above the longest report: eleven calls of a notes digest. ExecReport::STALE_AFTER_SECONDS stays above it. */
    public int $timeout = 300;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $reportId, public readonly bool $analyse = true)
    {
        $this->onQueue('ai');
    }

    public function handle(SuperAgent $agent, NotesDigest $notes): void
    {
        $report = ExecReport::query()->with('staff')->find($this->reportId);
        if ($report === null || $report->status !== ExecReportStatus::Running) {
            return;
        }
        $report->mode === ExecReportMode::Notes ? $notes->digest($report) : $agent->answer($report, $this->analyse);
    }

    public function failed(?Throwable $e): void
    {
        ExecReport::query()->whereKey($this->reportId)->where('status', ExecReportStatus::Running)->update(['status' => ExecReportStatus::Failed]);
    }
}
