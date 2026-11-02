<?php

namespace App\Enums;

/** What a support ticket is: written first by a user (or a visitor), or by staff («رسالة من فريق زون»). */
enum TicketType: string
{
    use Concerns;

    case Complaint = 'complaint';
    case Suggestion = 'suggestion';
    case Question = 'question';
    case Notice = 'notice';

    public function label(): string
    {
        return match ($this) {
            self::Complaint => 'شكوى',
            self::Suggestion => 'اقتراح',
            self::Question => 'استفسار',
            self::Notice => 'رسالة من فريق زون',
        };
    }

    /** What a user may open himself; a notice is staff's. @return list<string> */
    public static function forCustomers(): array
    {
        return [self::Complaint->value, self::Suggestion->value, self::Question->value];
    }
}
