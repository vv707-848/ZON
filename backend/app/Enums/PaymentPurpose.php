<?php

namespace App\Enums;

/** What a payment pays for. */
enum PaymentPurpose: string
{
    use Concerns;

    case Subscription = 'subscription';
    case UrgentRequest = 'urgent_request';
    case Featured = 'featured';
    case UrgentListing = 'urgent_listing';
    case AdLicence = 'ad_licence';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'اشتراك',
            self::UrgentRequest => 'طلب عاجل',
            self::Featured => 'تمييز «مميز»',
            self::UrgentListing => 'تمييز «عاجل»',
            self::AdLicence => 'رسوم الترخيص الإعلاني',
        };
    }
}
