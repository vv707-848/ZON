<?php

namespace App\Enums;

/** The owner's path for one property, in order. */
enum OwnerCaseStage: string
{
    use Concerns;

    case Contract = 'contract';
    case Fee = 'fee';
    case Licence = 'licence';
    case Listing = 'listing';
    case Active = 'active';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'عقد الوساطة',
            self::Fee => 'رسوم الترخيص',
            self::Licence => 'إصدار الترخيص',
            self::Listing => 'نشر العرض',
            self::Active => 'مفعّلة',
            self::Closed => 'مغلقة',
        };
    }
}
