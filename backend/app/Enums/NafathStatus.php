<?php

namespace App\Enums;

/** Identity check of an advertiser, once per account (customers.nafath_status). */
enum NafathStatus: string
{
    use Concerns;

    case None = 'none';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::None => 'لم يبدأ',
            self::Pending => 'قيد التحقق',
            self::Verified => 'موثّق',
            self::Rejected => 'مرفوض',
        };
    }
}
