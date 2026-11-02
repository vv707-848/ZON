<?php

namespace App\Enums;

/** The advertiser's answer to the suggested wording. */
enum ReviewDecision: string
{
    use Concerns;

    case Approve = 'approve';
    case Edit = 'edit';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'اعتماد',
            self::Edit => 'تعديل',
            self::Reject => 'رفض',
        };
    }
}
