<?php

namespace App\Enums;

/** Issued by REGA for the platform, or entered by the advertiser and verified. */
enum LicenceSource: string
{
    use Concerns;

    case Issued = 'issued';
    case Entered = 'entered';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'صادر عبر المنصة',
            self::Entered => 'أدخله المعلن',
        };
    }
}
