<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The base of every job on the schedule (routes/console.php). Each one is idempotent: running it twice, or late,
 * changes nothing more than running it once on time.
 */
abstract class ScheduledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    abstract public function handle(): void;
}
