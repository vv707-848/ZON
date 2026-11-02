<?php

namespace App\Enums;

/** A search request's state (requests.status). */
enum RequestStatus: string
{
    use Concerns;

    case Published = 'published';
    case Closed = 'closed';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'منشور',
            self::Closed => 'مغلق',
            self::Hidden => 'مخفي',
        };
    }
}
