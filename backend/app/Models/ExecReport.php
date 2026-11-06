<?php

namespace App\Models;

use App\Enums\ExecReportMode;
use App\Enums\ExecReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A SuperAgent report: what was asked → the approved templates it ran (its parts, each with its parameters and
 * rows) → the analysis. Archived «running» when asked for, and finished by App\Jobs\RunExecReport.
 *
 * @property list<array{report: string, params: array<string, mixed>, rows: list<array<string, mixed>>, row_count: int, status: string}> $parts
 */
class ExecReport extends Model
{
    public const UPDATED_AT = null;

    /** A report still running after this long lost its job (RunExecReport stops at 300 s): it is shown as failed. */
    public const STALE_AFTER_SECONDS = 360;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mode' => ExecReportMode::class, 'status' => ExecReportStatus::class, 'parts' => 'array', 'recommendations' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /** The status to show: a running report whose job died (a killed worker) is not left spinning forever. */
    public function shownStatus(): ExecReportStatus
    {
        return $this->status === ExecReportStatus::Running && $this->created_at?->lt(now()->subSeconds(self::STALE_AFTER_SECONDS))
            ? ExecReportStatus::Failed
            : $this->status;
    }
}
