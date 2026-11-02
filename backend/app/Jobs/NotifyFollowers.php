<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Blocks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** «نشر المتابَعين»: the followers of an advertiser hear of his new listing (their switch decides). */
class NotifyFollowers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $listingId)
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        $listing = Listing::query()->with('customer')->find($this->listingId);
        if ($listing === null || ! $listing->isLive()) {
            return;
        }
        $hidden = Blocks::hiddenFor($listing->customer);
        $listing->customer->followers()->whereNull('anonymized_at')->whereNotIn('customers.id', $hidden)
            ->chunkById(500, fn ($followers) => $followers->each(fn (Customer $f) => $f->notify(Notices::followedPublished($listing->customer, $listing))), 'customers.id', 'id');
    }
}
