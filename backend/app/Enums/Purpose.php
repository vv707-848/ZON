<?php

namespace App\Enums;

/** Sale or yearly rent. */
enum Purpose: string
{
    use Concerns;

    case Sale = 'sale';
    case Rent = 'rent';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'للبيع',
            self::Rent => 'للإيجار السنوي',
        };
    }
}
