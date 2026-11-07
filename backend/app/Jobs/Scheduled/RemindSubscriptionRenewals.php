<?php

namespace App\Jobs\Scheduled;

use App\Enums\SubscriptionSource;
use App\Integrations\Stores\Stores;
use App\Jobs\ScheduledJob;
use App\Models\Subscription;
use App\Notifications\Notices;
use Illuminate\Database\Eloquent\Builder;

/**
 * Three days before a period bought on the website ends, one reminder — not when the plan goes on after it (a
 * period bought ahead or granted), and not for the apps' plans, which the store itself renews.
 */
class RemindSubscriptionRenewals extends ScheduledJob
{
    public function __construct()
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        Subscription::query()->with(['customer', 'package'])->where('source', SubscriptionSource::Purchase)->whereNull('cancelled_at')
            ->whereNull('renewal_reminded_at')->whereBetween('ends_at', [now(), now()->addDays(3)])
            ->whereHas('payment', fn (Builder $q) => $q->whereNotIn('provider', Stores::NAMES))
            ->whereNotExists(fn ($q) => $q->from('subscriptions', 'later')->whereColumn('later.customer_id', 'subscriptions.customer_id')
                ->whereColumn('later.package_id', 'subscriptions.package_id')->whereNull('later.cancelled_at')->whereColumn('later.ends_at', '>', 'subscriptions.ends_at'))
            ->chunkById(200, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $subscription->forceFill(['renewal_reminded_at' => now()])->save();
                    $subscription->customer->notify(Notices::renewalReminder($subscription));
                }
            });
    }
}
