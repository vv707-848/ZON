<?php

namespace App\Enums;

/** A report's handling. */
enum ReportStatus: string
{
    use Concerns;

    case New = 'new';
    case Reviewing = 'reviewing';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديد',
            self::Reviewing => 'قيد المراجعة',
            self::Accepted => 'مقبول',
            self::Rejected => 'مرفوض',
        };
    }
}
