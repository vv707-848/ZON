<?php

namespace App\Enums;

/** Families or singles. */
enum Occupancy: string
{
    use Concerns;

    case Families = 'families';
    case Singles = 'singles';

    public function label(): string
    {
        return match ($this) {
            self::Families => 'عوائل',
            self::Singles => 'عزاب',
        };
    }
}
