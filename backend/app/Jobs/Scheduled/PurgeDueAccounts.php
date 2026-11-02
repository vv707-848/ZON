<?php

namespace App\Jobs\Scheduled;

use App\Actions\Accounts\PurgeAccount;
use App\Jobs\ScheduledJob;
use App\Models\DeletionRequest;

/** The deletion grace period is over: the account loses its identity and private desk. */
class PurgeDueAccounts extends ScheduledJob
{
    public function handle(): void
    {
        $purge = app(PurgeAccount::class);
        DeletionRequest::query()->with('customer')->whereNull('cancelled_at')->whereNull('purged_at')->where('purge_at', '<=', now())
            ->each(fn (DeletionRequest $deletion) => $purge($deletion));
    }
}
