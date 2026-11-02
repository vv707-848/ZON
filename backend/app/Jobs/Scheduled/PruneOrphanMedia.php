<?php

namespace App\Jobs\Scheduled;

use App\Jobs\ScheduledJob;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/** Photos uploaded but never attached (or detached) for a day are deleted — the file only when no copy uses it. */
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
                $shared = Media::query()->where('path', $media->path)->whereKeyNot($media->id)->exists();
                if (! $shared) {
                    Storage::disk($media->disk)->delete($media->path);
                }
                $media->delete();
            }
        });
    }
}
