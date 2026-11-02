<?php

namespace App\Enums;

/** A project ready now or on plan. */
enum Delivery: string
{
    use Concerns;

    case Ready = 'ready';
    case Offplan = 'offplan';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'جاهز',
            self::Offplan => 'على الخارطة',
        };
    }
}
