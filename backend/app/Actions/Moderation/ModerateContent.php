<?php

namespace App\Actions\Moderation;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Projects\ChangeProjectStatus;
use App\Actions\Support\SupportDesk;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Indexing\IndexSync;
use App\Models\Listing;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Models\SupportTicket;
use App\Notifications\Notices;
use App\Support\Actor;

/** Staff decisions on content: hide, put back, reject, «غير نشط» and back — through the state machine, audited, the advertiser told. */
final class ModerateContent
{
    public function __construct(
        private readonly ChangeListingStatus $listings,
        private readonly ChangeProjectStatus $projects,
        private readonly IndexSync $index,
        private readonly SupportDesk $desk,
    ) {}

    /**
     * «غير نشط» for a problem: the advertiser gets the reason as a message of «فريق زون», answers it in the
     * thread, and an update of the listing brings it back (as after two weeks without one).
     */
    public function deactivate(Listing $listing, string $reason): SupportTicket
    {
        ($this->listings)($listing, ListingStatus::Inactive, Actor::staff(Staff::user()));
        Audit::log('listing_deactivated', 'تحويل عرض إلى غير نشط', $listing, ['reason' => $reason]);

        return $this->desk->message($listing->customer, Staff::user(), "عرضك «{$listing->title}» صار غير نشط", $reason, $listing);
    }

    /** Back to «نشط» — or to «منتهي الترخيص» if its licence has ended (ChangeListingStatus). */
    public function activate(Listing $listing, ?string $note): ListingStatus
    {
        ($this->listings)($listing, ListingStatus::Published, Actor::staff(Staff::user()));
        Audit::log('listing_activated', 'تنشيط عرض', $listing, ['note' => $note, 'status' => $listing->status->value]);
        if ($note !== null && $note !== '' && $listing->status === ListingStatus::Published) {
            $this->desk->message($listing->customer, Staff::user(), "عرضك «{$listing->title}» عاد نشطاً", $note, $listing);
        }

        return $listing->status;
    }

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
        if ($to === RequestStatus::Published && ($deletion = $request->customer?->pendingDeletion) !== null) {
            $deletion->keepHidden('requests', $request->id);     // the account is being deleted: a cancellation publishes it
            $to = RequestStatus::Hidden;
        }
        $request->forceFill(['status' => $to, 'auto_hidden_at' => null, 'closed_at' => $to === RequestStatus::Closed ? now() : $request->closed_at])->save();
        $this->index->request($request);
        Audit::log("request_{$to->value}", 'إشراف طلب', $request, ['reason' => $reason]);
    }
}
