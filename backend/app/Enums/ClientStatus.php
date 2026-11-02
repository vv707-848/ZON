<?php

namespace App\Enums;

/** A CRM client. */
enum ClientStatus: string
{
    use Concerns;

    case Active = 'active';
    case Closed = 'closed';
    case Postponed = 'postponed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'نشط',
            self::Closed => 'مغلق',
            self::Postponed => 'مؤجل',
        };
    }
}
