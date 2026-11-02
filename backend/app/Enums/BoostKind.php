<?php

namespace App\Enums;

/** Featuring a listing, or an urgent request (P02 · R01). */
enum BoostKind: string
{
    use Concerns;

    case Featured = 'featured';
    case UrgentListing = 'urgent_listing';
    case UrgentRequest = 'urgent_request';

    public function label(): string
    {
        return match ($this) {
            self::Featured => 'مميز',
            self::UrgentListing => 'عاجل',
            self::UrgentRequest => 'طلب عاجل',
        };
    }
}
