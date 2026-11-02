<?php

namespace App\Actions\Moderation;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Projects\ChangeProjectStatus;
use App\Enums\ListingStatus;
use App\Enums\ReportStatus;
use App\Enums\RequestStatus;
use App\Indexing\IndexSync;
use App\Models\ContentReport;
use App\Models\Listing;
use App\Models\Project;
use App\Models\Rating;
use App\Models\SearchRequest;
use App\Notifications\Notices;
use App\Support\Actor;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Database\Eloquent\Model;

/** N open reports from N different accounts (Setting::ReportsToAutoHide) hide the content until staff decide. */
final class AutoHideReported
{
    public function __construct(private readonly ChangeListingStatus $listings, private readonly ChangeProjectStatus $projects, private readonly IndexSync $index) {}

    public function __invoke(Model $target): void
    {
        $reporters = ContentReport::query()->where('target_type', $target->getMorphClass())->where('target_id', $target->getKey())
            ->whereIn('status', [ReportStatus::New, ReportStatus::Reviewing])->distinct()->count('reporter_id');
        if ($reporters < Settings::int(Setting::ReportsToAutoHide)) {
            return;
        }

        match (true) {
            $target instanceof Listing && $target->status->canBecome(ListingStatus::Hidden) => $this->hideListing($target),
            $target instanceof Project && $target->status->canBecome(ListingStatus::Hidden) => ($this->projects)($target, ListingStatus::Hidden, Actor::system(), ['auto_hidden_at' => now()]),
            $target instanceof SearchRequest && $target->status === RequestStatus::Published => $this->hideRequest($target),
            $target instanceof Rating && $target->hidden_at === null => $target->forceFill(['hidden_at' => now()])->save(),
            default => null,     // accounts and messages wait for a person
        };
    }

    private function hideListing(Listing $listing): void
    {
        ($this->listings)($listing, ListingStatus::Hidden, Actor::system(), ['auto_hidden_at' => now()]);
        $listing->customer->notify(Notices::listingHidden($listing));
    }

    private function hideRequest(SearchRequest $request): void
    {
        $request->forceFill(['status' => RequestStatus::Hidden, 'auto_hidden_at' => now()])->save();
        $this->index->request($request);
    }
}
