<?php

namespace App\Services\Billing;

use App\Enums\AreaRequestsAccess;
use App\Enums\BoostKind;
use App\Enums\BoostVia;
use App\Enums\CustomerRole;
use App\Enums\ListingStatus;
use App\Models\BoostUsage;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Subscription;
use App\Support\RequestCache;

/**
 * What an account may do now: its current subscription's package, or the base package of its role
 * (free-seeker · free-broker · owner · developer). Every limit is a column of `packages`, edited from the panel.
 * One instance per account per request (for()).
 */
final class Entitlements
{
    private ?Subscription $subscription;

    private Package $package;

    private function __construct(public readonly Customer $customer)
    {
        $this->subscription = $customer->subscriptions()->current()->with('package')->latest('ends_at')->first();
        $this->package = $this->subscription->package
            ?? Package::query()->where('code', self::baseCode($customer->role))->firstOrFail();
    }

    public static function for(Customer $customer): self
    {
        return RequestCache::remember("entitlements:{$customer->id}", fn () => new self($customer));
    }

    /** Drop the cached answer (after a purchase, a grant, a boost). */
    public static function forget(Customer $customer): void
    {
        RequestCache::forget("entitlements:{$customer->id}");
    }

    public static function baseCode(CustomerRole $role): string
    {
        return match ($role) {
            CustomerRole::Seeker => 'free-seeker',
            CustomerRole::Broker => 'free-broker',
            CustomerRole::Owner => Package::OWNER,
            CustomerRole::Developer => 'developer',
        };
    }

    public function package(): Package
    {
        return $this->package;
    }

    public function subscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function isSubscribed(): bool
    {
        return $this->subscription !== null;
    }

    /** A new listing fits in the plan (listings not sold, rejected or deleted count). */
    public function canAddListing(): bool
    {
        $limit = $this->package->listing_limit;

        return $limit === null || $this->customer->listings()
            ->whereNotIn('status', [ListingStatus::Sold, ListingStatus::Rejected])->count() < $limit;
    }

    public function canAddInventory(): bool
    {
        $limit = $this->package->inventory_limit;

        return $limit === null || $this->customer->inventoryItems()->count() < $limit;
    }

    public function preferencesLimit(): int
    {
        return max(1, $this->package->preferences_limit);
    }

    public function areaRequests(): AreaRequestsAccess
    {
        return $this->customer->role === CustomerRole::Developer ? AreaRequestsAccess::Full : $this->package->area_requests;
    }

    public function canSearchRequests(): bool
    {
        return $this->package->can_search_requests;
    }

    /** Daily AI cap of one use: search · matching · summary · chat. */
    public function aiDaily(string $use): int
    {
        return (int) $this->package->getAttribute("ai_{$use}_daily");
    }

    /** Boosts of a kind still included in the current period (0 without a subscription). */
    public function quotaLeft(BoostKind $kind): int
    {
        if ($this->subscription === null) {
            return 0;
        }
        $included = match ($kind) {
            BoostKind::Featured => $this->package->featured_quota,
            BoostKind::UrgentListing => $this->package->urgent_listing_quota,
            BoostKind::UrgentRequest => $this->package->urgent_request_quota,
        };
        $used = BoostUsage::query()->where('subscription_id', $this->subscription->id)
            ->where('kind', $kind)->where('via', BoostVia::Quota)->count();

        return max(0, $included - $used);
    }

    /** @return array<string, int> all three balances (GET /subscription, the plans screen) */
    public function balances(): array
    {
        $balances = [];
        foreach (BoostKind::cases() as $kind) {
            $balances[$kind->value] = $this->quotaLeft($kind);
        }

        return $balances;
    }
}
