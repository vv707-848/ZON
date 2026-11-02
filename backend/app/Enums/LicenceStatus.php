<?php

namespace App\Enums;

/** An advertising licence. */
enum LicenceStatus: string
{
    use Concerns;

    case Pending = 'pending';
    case Valid = 'valid';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد التحقق',
            self::Valid => 'ساري',
            self::Rejected => 'مرفوض',
            self::Expired => 'منتهٍ',
        };
    }
}
