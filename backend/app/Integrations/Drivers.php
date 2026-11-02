<?php

namespace App\Integrations;

/**
 * The driver chosen for each external service (config/services.php) and the production rule:
 * a simulated driver ("fake") runs in production only when PRODUCTION_ALLOWS_FAKE lists the
 * service (e.g. "rega,nafath" until REGA grants API access). Read by IntegrationServiceProvider
 * to bind the drivers and by SystemHealth to report them.
 */
final class Drivers
{
    public const FAKE = 'fake';

    /** Every external service, with its Arabic name. */
    public const SERVICES = [
        'sms' => 'رسائل التحقق',
        'rega' => 'الهيئة العامة للعقار',
        'nafath' => 'نفاذ',
        'payments' => 'الدفع',
        'push' => 'الإشعارات',
        'invoicing' => 'الفوترة',
        'image_hash' => 'بصمة الصور',
    ];

    public static function of(string $service): string
    {
        return (string) config("services.{$service}.driver");
    }

    public static function fakeAllowedInProduction(string $service): bool
    {
        $allowed = array_map('trim', explode(',', (string) config('services.production_allows_fake')));

        return in_array($service, $allowed, true);
    }
}
