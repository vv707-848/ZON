<?php

namespace App\Enums;

/** Who a plan is for. */
enum PackageAudience: string
{
    use Concerns;

    case Broker = 'broker';
    case Seeker = 'seeker';
    case Owner = 'owner';
    case Developer = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::Broker => 'الوسيط',
            self::Seeker => 'الباحث',
            self::Owner => 'المالك',
            self::Developer => 'المطوّر',
        };
    }
}
