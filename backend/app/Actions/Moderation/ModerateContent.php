<?php

namespace App\Actions\Moderation;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Projects\ChangeProjectStatus;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Indexing\IndexSync;
use App\Models\Listing;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Notifications\Notices;
use App\Support\Actor;

/** Staff decisions on content: hide, put back, reject — through the state machine, audited, the advertiser told. */
final class ModerateContent
{
    public function __construct(private readonly ChangeListingStatus $listings, private readonly ChangeProjectStatus $projects, private readonly IndexSync $index) {}

    public function listing(Listing $listing, ListingStatus $to, string $reason): void
    {
        ($this->listings)($listing, $to, Actor::staff(Staff::user()));
        Audit::log("listing_{$to->value}", 'إشراف عرض', $listing, ['reason' => $reason]);
        if ($to === ListingStatus::Hidden || $to === ListingStatus::Rejected) {
            $listing->customer->notify(Notices::listingHidden($listing));
        }
    }

    public function project(Project $project, ListingStatus $to, string $reason): void
    {
        ($this->projects)($project, $to, Actor::staff(Staff::user()));
        Audit::log("project_{$to->value}", 'إشراف مشروع', $project, ['reason' => $reason]);
    }

    public function request(SearchRequest $request, RequestStatus $to, string $reason): void
    {
        $request->forceFill(['status' => $to, 'auto_hidden_at' => null, 'closed_at' => $to === RequestStatus::Closed ? now() : $request->closed_at])->save();
        $this->index->request($request);
        Audit::log("request_{$to->value}", 'إشراف طلب', $request, ['reason' => $reason]);
    }
}
