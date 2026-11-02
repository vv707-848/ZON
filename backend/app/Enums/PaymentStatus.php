<?php

namespace App\Enums;

/** A payment's state; nothing is activated before `paid` (§12). */
enum PaymentStatus: string
{
    use Concerns;

    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Unapplied = 'unapplied';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الدفع',
            self::Paid => 'مدفوعة',
            self::Failed => 'فاشلة',
            self::Unapplied => 'مدفوعة بلا تطبيق',
            self::Refunded => 'مستردة',
        };
    }
}
