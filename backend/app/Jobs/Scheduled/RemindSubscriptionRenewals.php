<?php

namespace App\Jobs\Scheduled;

use App\Enums\SubscriptionSource;
use App\Jobs\ScheduledJob;
use App\Models\Subscription;
use App\Notifications\Notices;

/** Three days before a bought period ends, one reminder. */
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
            ->chunkById(200, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $subscription->forceFill(['renewal_reminded_at' => now()])->save();
                    $subscription->customer->notify(Notices::renewalReminder($subscription));
                }
            });
    }
}
