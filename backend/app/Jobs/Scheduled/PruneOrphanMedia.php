<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ScheduledJob;
use App\Models\Media;

/** Photos uploaded but never attached (or detached) for a day are deleted — the files only when no copy uses them. */
class PruneOrphanMedia extends ScheduledJob
{
    public function __construct()
    {
        $this->onQueue('media');
    }

    public function handle(): void
    {
        Media::query()->whereNull('mediable_id')->where('updated_at', '<', now()->subDay())->chunkById(200, function ($orphans) {
            foreach ($orphans as $media) {
                $media->deleteWithFiles();
            }
        });
    }
}
