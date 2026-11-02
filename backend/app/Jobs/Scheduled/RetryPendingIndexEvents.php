<?php

namespace App\Jobs\Scheduled;

use App\Jobs\PushIndexEvent;
use App\Jobs\ScheduledJob;
use App\Models\IndexEvent;

/** Signals still unacknowledged after 5 minutes (never sent, or sent ≥ 15 minutes ago) are sent again, 500 at a time. */
class RetryPendingIndexEvents extends ScheduledJob
{
    public function handle(): void
    {
        IndexEvent::query()->whereNull('acked_at')->where('created_at', '<=', now()->subMinutes(5))
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere('sent_at', '<=', now()->subMinutes(15)))
            ->orderBy('id')->limit(500)->pluck('id')
            ->each(fn (int $id) => PushIndexEvent::dispatch($id));
    }
}
