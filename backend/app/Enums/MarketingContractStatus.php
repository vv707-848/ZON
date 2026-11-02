<?php

namespace App\Enums;

/** A developer's marketing contract. */
enum MarketingContractStatus: string
{
    use Concerns;

    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'ساري',
            self::Ended => 'منتهٍ',
            self::Cancelled => 'ملغى',
        };
    }
}
