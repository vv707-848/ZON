<?php

namespace App\Actions\Listings;

use App\Enums\ListingStatus;
use App\Jobs\NotifyFollowers;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Actor;

/** A listing goes public: the status change, and on its first publication the advertiser and his followers hear of it. */
final class PublishListing
{
    public function __construct(private readonly ChangeListingStatus $status) {}

    public function __invoke(Listing $listing, Actor $actor): Listing
    {
        $first = $listing->published_at === null;
        ($this->status)($listing, ListingStatus::Published, $actor);

        if ($first) {
            $listing->customer->notify(Notices::listingPublished($listing));
            NotifyFollowers::dispatch($listing->id)->afterCommit();
        }

        return $listing;
    }
}
