<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Blocks;
use App\Support\Sandbox;
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
        $followers = $listing->customer->followers()->whereNull('anonymized_at')->whereNotIn('customers.id', Blocks::blockedWith($listing->customer));
        Sandbox::audience($followers, $listing->customer, 'customers.is_tester')
            ->chunkById(500, fn ($followers) => $followers->each(fn (Customer $f) => $f->notify(Notices::followedPublished($listing->customer, $listing))), 'customers.id', 'id');
    }
}
