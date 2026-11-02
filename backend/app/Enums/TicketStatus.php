<?php

namespace App\Enums;

/** A support ticket. */
enum TicketStatus: string
{
    use Concerns;

    case Open = 'open';
    case Answered = 'answered';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'مفتوحة',
            self::Answered => 'تم الرد',
            self::Closed => 'مغلقة',
        };
    }
}
