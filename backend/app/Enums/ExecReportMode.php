<?php

namespace App\Enums;

/** How a SuperAgent report was asked for (exec_reports.mode), which decides what its job does. */
enum ExecReportMode: string
{
    use Concerns;

    /** A message in Arabic, of one question or a few: the model chooses the approved reports and their parameters. */
    case Question = 'question';

    /** A focus, or none: the model chooses one report at a time from what the earlier ones found. */
    case Explore = 'explore';

    /** A template the staff chose («الوضع اليدوي», «تقارير السوق»): no model chooses anything. */
    case Manual = 'manual';

    /** «قراءة ملاحظات العملاء» (App\SuperAgent\NotesDigest). */
    case Notes = 'notes';

    public function label(): string
    {
        return match ($this) {
            self::Question => 'سؤال',
            self::Explore => 'استكشاف',
            self::Manual => 'يدوي',
            self::Notes => 'ملاحظات العملاء',
        };
    }
}
