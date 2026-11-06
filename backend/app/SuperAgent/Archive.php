<?php

namespace App\SuperAgent;

use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use App\Jobs\RunExecReport;
use App\Models\ExecReport;
use App\Models\User;
use App\Support\Trace;
use RuntimeException;

/**
 * Opening a SuperAgent report (exec_reports): within the daily cap, one at a time per superadmin, archived
 * «running» and handed to its job — the panel never waits on the model.
 */
final class Archive
{
    /** @param list<array{report: string, params: array<string, mixed>}> $parts what the staff chose themselves (none when the model chooses) */
    public static function open(User $staff, ExecReportMode $mode, string $question, array $parts = [], bool $analyse = true): ExecReport
    {
        if (DailyCap::left($staff) < 1) {
            throw new RuntimeException('بلغت سقف التقارير اليومي.');
        }
        if (self::running($staff)) {
            throw new RuntimeException('تقرير قيد الإعداد — انتظر حتى يكتمل.');
        }
        $report = ExecReport::query()->create([
            'staff_id' => $staff->id,
            'mode' => $mode,
            'question' => mb_substr($question, 0, 500),
            'parts' => array_map(fn (array $part) => $part + ['rows' => [], 'row_count' => 0, 'status' => 'pending'], $parts),
            'status' => ExecReportStatus::Running,
            'trace_id' => Trace::id(),
            'created_at' => now(),
        ]);
        RunExecReport::dispatch($report->id, $analyse);

        return $report->refresh();
    }

    public static function running(User $staff): bool
    {
        return ExecReport::query()->where('staff_id', $staff->id)->where('status', ExecReportStatus::Running)
            ->where('created_at', '>=', now()->subSeconds(ExecReport::STALE_AFTER_SECONDS))->exists();
    }
}
