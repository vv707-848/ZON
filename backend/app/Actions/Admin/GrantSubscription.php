<?php

namespace App\Actions\Admin;

use App\Enums\SubscriptionSource;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Subscription;
use App\Notifications\Notices;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «منح» a plan for some days without payment (a gift, a compensation, a trial) — written to the audit log. Days of
 * a plan the account already has are added after its last period, never spent alongside it.
 */
final class GrantSubscription
{
    public function __invoke(Customer $customer, Package $package, int $days, string $reason): Subscription
    {
        if ($package->audience->value !== $customer->role->value || $days < 1) {
            throw new InvalidArgumentException('The plan is not for this account.');
        }
        $subscription = DB::transaction(function () use ($customer, $package, $days) {
            $starts = Subscription::nextStart($customer->id, $package->id);

            return Subscription::query()->create([
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'source' => SubscriptionSource::Grant,
                'granted_by' => Staff::user()->id,
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addDays($days),
            ]);
        });
        Entitlements::forget($customer);
        Audit::log('subscription_granted', 'منح باقة', $customer, ['package' => $package->code, 'days' => $days, 'reason' => $reason]);
        $customer->notify(Notices::planActivated($package));

        return $subscription;
    }
}
