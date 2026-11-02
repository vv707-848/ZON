<?php

namespace App\Enums;

/** Length of one paid period. */
enum PackagePeriod: string
{
    use Concerns;

    case Month = 'month';
    case Year = 'year';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'شهري',
            self::Year => 'سنوي',
            self::None => 'بلا مدة',
        };
    }
}
