<?php

namespace App\Enums;

/** A commission due after a sale. */
enum CommissionStatus: string
{
    use Concerns;

    case Due = 'due';
    case Paid = 'paid';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Due => 'مستحقة',
            self::Paid => 'مدفوعة',
            self::Waived => 'معفاة',
        };
    }
}
