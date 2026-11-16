<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The base of every job on the schedule (routes/console.php). Each one is idempotent: running it twice, or late,
 * changes nothing more than running it once on time.
 *
 * And one at a time: while a run waits or works, the schedule's next one is not queued beside it (onOneServer only
 * keeps two schedulers from queueing the same minute). The lock ends with the run, or with its timeout if the
 * worker died.
 */
abstract class ScheduledJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    abstract public function handle(): void;
}
