<?php

namespace App\Enums;

/** Where a boost came from. */
enum BoostVia: string
{
    use Concerns;

    case Quota = 'quota';
    case Payment = 'payment';
    case Grant = 'grant';

    public function label(): string
    {
        return match ($this) {
            self::Quota => 'من الرصيد',
            self::Payment => 'مدفوع',
            self::Grant => 'منحة',
        };
    }
}
