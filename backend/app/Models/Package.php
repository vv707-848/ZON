<?php

namespace App\Models;

use App\Enums\AreaRequestsAccess;
use App\Enums\PackageAudience;
use App\Enums\PackagePeriod;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/** A plan and its limits (the app's Pricing / kPlanQuotas, now edited from the panel without a release). */
class Package extends Model
{
    public const BROKER_MONTH = 'broker-month';

    public const BROKER_YEAR = 'broker-year';

    public const SEEKER = 'seeker';

    public const OWNER = 'owner';

    /** The plans of an account without a subscription, and the developer's (Entitlements::baseCode). */
    public const FREE_SEEKER = 'free-seeker';

    public const FREE_BROKER = 'free-broker';

    public const DEVELOPER = 'developer';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audience' => PackageAudience::class,
            'period' => PackagePeriod::class,
            'area_requests' => AreaRequestsAccess::class,
            'can_search_requests' => 'boolean',
            'can_manage_owners' => 'boolean',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function price(): Money
    {
        return new Money($this->price_halalas);
    }

    public function days(): ?int
    {
        return match ($this->period) {
            PackagePeriod::Month => 30,
            PackagePeriod::Year => 365,
            PackagePeriod::None => null,
        };
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->price_halalas > 0 && $this->period !== PackagePeriod::None;
    }
}
