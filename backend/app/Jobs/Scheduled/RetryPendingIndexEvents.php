<?php

namespace App\Jobs\Scheduled;

use App\Jobs\PushIndexEvent;
use App\Jobs\ScheduledJob;
use App\Models\IndexEvent;

/**
 * Signals still unacknowledged after 5 minutes (never sent, or sent ≥ 15 minutes ago) are sent again, 500 at a time,
 * the least tried first — so signals that keep failing never hold the window against the rest.
 *
 * Not for ever: a signal the service refused (a 4xx is final: sending it again changes nothing) and one tried
 * `zon.index.max_attempts` times stay «فاشلة» in the panel, where «إعادة الإرسال» sends them by hand.
 */
class RetryPendingIndexEvents extends ScheduledJob
{
    public function handle(): void
    {
        IndexEvent::query()->whereNull('acked_at')->where('created_at', '<=', now()->subMinutes(5))
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere('sent_at', '<=', now()->subMinutes(15)))
            ->where('attempts', '<', (int) config('zon.index.max_attempts'))
            ->where(fn ($q) => $q->whereNull('last_error')->orWhere('last_error', 'not like', 'HTTP 4%'))
            ->orderBy('attempts')->orderBy('id')->limit(500)->pluck('id')
            ->each(fn (int $id) => PushIndexEvent::dispatch($id));
    }
}
