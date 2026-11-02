<?php

namespace App\Enums;

/** What staff did about a report. */
enum ReportAction: string
{
    use Concerns;

    case Ignored = 'ignored';
    case Warned = 'warned';
    case Hidden = 'hidden';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Ignored => 'تجاهل',
            self::Warned => 'تنبيه',
            self::Hidden => 'إخفاء',
            self::Banned => 'إيقاف الحساب',
        };
    }
}
