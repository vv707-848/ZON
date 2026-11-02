<?php

namespace App\Enums;

/** The app's notification kinds (NoteKind in lib/data/models/notification.dart). */
enum NoteKind: string
{
    use Concerns;

    case Match = 'match';
    case Message = 'message';
    case Listing = 'listing';
    case Request = 'request';
    case Plan = 'plan';

    public function label(): string
    {
        return match ($this) {
            self::Match => 'مطابقة',
            self::Message => 'رسالة',
            self::Listing => 'عرض',
            self::Request => 'طلب',
            self::Plan => 'اشتراك',
        };
    }
}
