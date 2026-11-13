<?php

namespace App\Actions\Billing;

use App\Enums\BoostKind;
use App\Enums\BoostVia;
use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Enums\PaymentPurpose;
use App\Enums\RequestStatus;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Models\BoostUsage;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Money;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * «مميز» / «عاجل» on a listing, or an urgent request: from the plan's balance, a payment, or a staff grant.
 * «مميز» on a developer's published project: free while fewer than ProjectFeaturedFree of his projects are featured
 * free at the time, then paid (ProjectFeaturedPrice) — so not every project of his shows featured.
 * A boost still running → ALREADY_ACTIVE. No balance and no payment → PAYMENT_REQUIRED with the price, so the
 * app opens checkout (purpose + target). The balance is spent under a lock: two taps never spend it twice.
 */
final class ApplyBoost
{
    public function __construct(private readonly IndexSync $index) {}

    public function __invoke(Customer $customer, Model $target, BoostKind $kind, ?Payment $payment = null, ?User $grantedBy = null): BoostUsage
    {
        $this->assertTarget($target, $kind);

        return DB::transaction(function () use ($customer, $target, $kind, $payment, $grantedBy) {
            $target->refresh();
            if ($this->isRunning($target, $kind)) {
                throw ApiException::of(ErrorCode::AlreadyActive);
            }

            $subscription = null;
            $via = $grantedBy !== null ? BoostVia::Grant : ($payment !== null ? BoostVia::Payment : BoostVia::Quota);
            if ($via === BoostVia::Quota) {
                Entitlements::forget($customer);
                $subscription = Entitlements::for($customer)->subscription();
                if ($subscription !== null) {
                    Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();
                    Entitlements::forget($customer);
                }
                $left = $target instanceof Project ? self::freeProjectsLeft($customer) : ($subscription === null ? 0 : Entitlements::for($customer)->quotaLeft($kind));
                if ($left < 1) {
                    throw ApiException::of(ErrorCode::PaymentRequired, [
                        'purpose' => PaymentPurpose::from($kind->value)->value,
                        'price' => self::price($kind, $target)->riyals(),
                        'target' => ['type' => $target->getMorphClass(), 'id' => $target->getKey()],
                    ]);
                }
                if ($target instanceof Project) {
                    $subscription = null;   // his free featuring, not a plan's balance
                }
            }

            $until = now()->addDays(Settings::int($kind === BoostKind::UrgentRequest ? Setting::UrgentRequestDays : Setting::BoostDays));
            match ($kind) {
                BoostKind::Featured => $target->forceFill(['featured_until' => $until])->save(),
                BoostKind::UrgentListing => $target->forceFill(['urgent_until' => $until])->save(),
                BoostKind::UrgentRequest => $target->forceFill(['urgent' => true])->save(),
            };
            if ($target instanceof SearchRequest) {
                $this->index->request($target);
            }
            Entitlements::forget($customer);

            return BoostUsage::query()->create([
                'customer_id' => $customer->id,
                'kind' => $kind,
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'via' => $via,
                'subscription_id' => $subscription?->id,
                'payment_id' => $payment?->id,
                'granted_by' => $grantedBy?->id,
                'starts_at' => now(),
                'active_until' => $until,
                'created_at' => now(),
            ]);
        });
    }

    /** Free featurings a developer has left now: the free ones still running count, paid ones do not. Locked by the caller. */
    public static function freeProjectsLeft(Customer $developer): int
    {
        Customer::query()->whereKey($developer->id)->lockForUpdate()->first();   // two taps never take the last one twice
        $running = BoostUsage::query()->where('customer_id', $developer->id)->where('target_type', (new Project)->getMorphClass())
            ->where('kind', BoostKind::Featured)->where('via', BoostVia::Quota)->where('active_until', '>', now())->count();

        return max(0, Settings::int(Setting::ProjectFeaturedFree) - $running);
    }

    /** The one-off price once the balance is used (Setting), gross; a project's featuring has its own. */
    public static function price(BoostKind $kind, ?Model $target = null): Money
    {
        if ($target instanceof Project && $kind === BoostKind::Featured) {
            return Settings::money(Setting::ProjectFeaturedPrice);
        }

        return Settings::money(match ($kind) {
            BoostKind::Featured => Setting::FeaturedPrice,
            BoostKind::UrgentListing => Setting::UrgentListingPrice,
            BoostKind::UrgentRequest => Setting::UrgentRequestPrice,
        });
    }

    private function assertTarget(Model $target, BoostKind $kind): void
    {
        $ok = match ($kind) {
            BoostKind::Featured => ($target instanceof Listing && $target->isLive()) || ($target instanceof Project && $target->status === ListingStatus::Published),
            BoostKind::UrgentListing => $target instanceof Listing && $target->isLive(),
            BoostKind::UrgentRequest => $target instanceof SearchRequest && $target->status === RequestStatus::Published,
        };
        if (! $ok) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
    }

    private function isRunning(Model $target, BoostKind $kind): bool
    {
        return match (true) {
            $target instanceof Listing && $kind === BoostKind::Featured => $target->isFeatured(),
            $target instanceof Listing && $kind === BoostKind::UrgentListing => $target->isUrgent(),
            $target instanceof Project => $target->featured_until?->isFuture() ?? false,
            $target instanceof SearchRequest => $target->urgent,
            default => false,
        };
    }
}
