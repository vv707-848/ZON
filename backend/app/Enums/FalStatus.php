<?php

namespace App\Enums;

/** A broker's FAL licence as REGA answered (customers.fal_status). */
enum FalStatus: string
{
    use Concerns;

    case None = 'none';
    case Pending = 'pending';
    case Valid = 'valid';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::None => 'لا ينطبق',
            self::Pending => 'قيد التحقق',
            self::Valid => 'سارية',
            self::Rejected => 'مرفوضة',
            self::Expired => 'منتهية',
        };
    }
}
