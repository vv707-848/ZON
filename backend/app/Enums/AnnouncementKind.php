<?php

namespace App\Enums;

/**
 * What a staff announcement is, which decides how far it may go (App Store Review Guideline 4.5.4; PDPL Art. 25):
 * - service — running the platform (a meeting for the brokers, a policy change, maintenance): the bell and a push
 *   to everyone, with no switch to turn it off;
 * - promotion — selling something (a plan, a discount, a campaign): the bell for everyone, the push only to those
 *   who turned «العروض والتسويق» on.
 */
enum AnnouncementKind: string
{
    use Concerns;

    case Service = 'service';
    case Promotion = 'promotion';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'خدمة',
            self::Promotion => 'ترويج',
        };
    }
}
