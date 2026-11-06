<?php

namespace App\Enums;

/**
 * Where a SuperAgent report stands (exec_reports.status). A report is archived «running» the moment it is asked
 * for, and its job (App\Jobs\RunExecReport) leaves it in one of the three others.
 */
enum ExecReportStatus: string
{
    use Concerns;

    case Running = 'running';
    case Ok = 'ok';
    case NoAnalysis = 'no_analysis';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'قيد الإعداد',
            self::Ok => 'تحليل موثّق',
            self::NoAnalysis => 'بيانات بلا تحليل',
            self::Failed => 'تعذّر التقرير',
        };
    }
}
