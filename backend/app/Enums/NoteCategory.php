<?php

namespace App\Enums;

/** Category of a client or owner note — stored in English, shown in Arabic (§4). */
enum NoteCategory: string
{
    use Concerns;

    case Interested = 'interested';
    case Rejected = 'rejected';
    case Cash = 'cash';
    case Offered = 'offered';
    case Followup = 'followup';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'مهتم',
            self::Rejected => 'رفض',
            self::Cash => 'كاش',
            self::Offered => 'تم العرض',
            self::Followup => 'متابعة',
            self::Closed => 'مغلق',
        };
    }
}
