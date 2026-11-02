<?php

namespace App\Jobs\Scheduled;

use App\Enums\CustomerRole;
use App\Jobs\CheckBrokerLicence;
use App\Jobs\ScheduledJob;
use App\Models\Customer;

/** Nightly: brokers' FAL licences checked more than a week ago, or about to expire, are checked again. */
class RecheckBrokerLicenses extends ScheduledJob
{
    public function handle(): void
    {
        Customer::query()->where('role', CustomerRole::Broker)->whereNotNull('fal_number')->whereNull('anonymized_at')
            ->where(fn ($q) => $q->whereNull('fal_checked_at')->orWhere('fal_checked_at', '<', now()->subWeek())->orWhere('fal_expires_at', '<', now()->addDays(14)))
            ->pluck('id')->each(fn (int $id) => CheckBrokerLicence::dispatch($id));
    }
}
