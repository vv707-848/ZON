<?php

namespace App\Enums;

/** Floor of an apartment / office. */
enum FloorLevel: string
{
    use Concerns;

    case Ground = 'ground';
    case First = 'first';
    case Second = 'second';
    case Annex = 'annex';
    case Number = 'number';

    public function label(): string
    {
        return match ($this) {
            self::Ground => 'الأرضي',
            self::First => 'الأول',
            self::Second => 'الثاني',
            self::Annex => 'الملحق',
            self::Number => 'رقم',
        };
    }
}
