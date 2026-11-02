<?php

namespace App\Enums;

/** Use of a land or a unit. */
enum LandUse: string
{
    use Concerns;

    case Residential = 'residential';
    case Commercial = 'commercial';
    case Industrial = 'industrial';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'سكني',
            self::Commercial => 'تجاري',
            self::Industrial => 'صناعي',
            self::Mixed => 'تجاري سكني',
        };
    }
}
