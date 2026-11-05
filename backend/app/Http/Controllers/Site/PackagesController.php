<?php

namespace App\Http\Controllers\Site;

use App\Actions\Billing\ApplyBoost;
use App\Enums\BoostKind;
use App\Enums\PackageAudience;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Contracts\View\View;

/**
 * «الباقات والأسعار» (REGA's subscription page of the launch plan): every plan from `packages` — the same rows and
 * prices the app shows — by audience, the one-off prices and the owner's terms from the settings.
 */
class PackagesController extends Controller
{
    public function __invoke(): View
    {
        $plans = Package::query()->where('is_active', true)->orderBy('sort')->get()->groupBy(fn (Package $p) => $p->audience->value);

        return view('site.packages', [
            'audiences' => array_filter(array_map(fn (PackageAudience $a) => [$a, $plans->get($a->value, collect())], PackageAudience::cases()), fn ($row) => $row[1]->isNotEmpty()),
            'oneOff' => [
                'تمييز العرض «مميز»' => ApplyBoost::price(BoostKind::Featured),
                'العرض العاجل' => ApplyBoost::price(BoostKind::UrgentListing),
                'الطلب العاجل' => ApplyBoost::price(BoostKind::UrgentRequest),
            ],
            'boostDays' => Settings::int(Setting::BoostDays),
            'licenceFee' => Settings::money(Setting::AdLicenceFee),
            'ownerPercent' => Settings::string(Setting::OwnerCommissionPercent),
            'brokerShare' => Settings::string(Setting::BrokerFreeSharePercent),
            'vat' => Settings::bool(Setting::VatRegistered) ? Settings::string(Setting::VatRate) : null,
        ]);
    }
}
