<?php

namespace App\Jobs\Concerns;

/**
 * For a wording job that implements ShouldBeUniqueUntilProcessing: one waiting per subject, and only until it
 * starts. Two saves close together ask the AI once (the job reads the text when it runs), and an edit while it runs
 * sends its own, which the AI answers about the new text.
 */
trait WaitsOnce
{
    public int $uniqueFor = 60;

    /**
     * Sends the job for [id] after a save: one already waiting takes it; a queue that cannot take it fails nothing
     * saved (RequeueStuckWording sends it again). A pending dispatch sends (and takes the unique lock) in its
     * destructor, so it is dropped here, inside the rescue.
     */
    public static function sendFor(int $id): void
    {
        rescue(function () use ($id) {
            $pending = self::dispatch($id);
            unset($pending);
        }, report: true);
    }
}
