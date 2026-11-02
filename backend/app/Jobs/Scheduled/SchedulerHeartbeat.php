<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ScheduledJob;
use Illuminate\Support\Facades\Cache;

/** Proof the scheduler and a worker run: zon:doctor and «صحة النظام» read its age (alarm after 180 s). */
class SchedulerHeartbeat extends ScheduledJob
{
    public const CACHE_KEY = 'zon.heartbeat';

    public function handle(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }
}
