<?php

namespace App\Enums;

/** How a subscription started. */
enum SubscriptionSource: string
{
    use Concerns;

    case Purchase = 'purchase';
    case Grant = 'grant';
    case Contract = 'contract';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'شراء',
            self::Grant => 'منحة من الإدارة',
            self::Contract => 'عقد تسويق',
        };
    }
}
