<?php

namespace App\Jobs\Scheduled;

use App\Actions\Accounts\PurgeAccount;
use App\Jobs\ScheduledJob;
use App\Models\DeletionRequest;

/**
 * The deletion grace period is over: the account loses its identity and private desk. One account that fails is
 * reported and retried tomorrow; it never holds back the accounts after it.
 */
class PurgeDueAccounts extends ScheduledJob
{
    public function handle(): void
    {
        $purge = app(PurgeAccount::class);
        DeletionRequest::query()->with('customer')->whereNull('cancelled_at')->whereNull('purged_at')->where('purge_at', '<=', now())
            ->each(fn (DeletionRequest $deletion) => rescue(fn () => $purge($deletion)));
    }
}
