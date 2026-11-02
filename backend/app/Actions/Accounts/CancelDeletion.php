<?php

namespace App\Actions\Accounts;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Projects\ChangeProjectStatus;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Indexing\IndexSync;
use App\Models\ConversationParticipant;
use App\Models\DeletionRequest;
use App\Models\Listing;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/** Cancels a pending deletion (the owner signed in, or staff) and restores only what that request hid. */
final class CancelDeletion
{
    public function __construct(
        private readonly ChangeListingStatus $listingStatus,
        private readonly ChangeProjectStatus $projectStatus,
        private readonly IndexSync $index,
    ) {}

    public function __invoke(DeletionRequest $deletion, string $by): void
    {
        if (! $deletion->isPending()) {
            return;
        }

        DB::transaction(function () use ($deletion, $by) {
            $customer = $deletion->customer;
            $actor = Actor::customer($customer);
            $hidden = $deletion->hidden_items ?? [];

            foreach (Listing::query()->whereKey(array_keys($hidden['listings'] ?? []))->where('status', ListingStatus::Hidden)->get() as $listing) {
                $was = ListingStatus::from($hidden['listings'][$listing->id]);
                ($this->listingStatus)($listing, $was === ListingStatus::Paused ? ListingStatus::Paused : ListingStatus::Published, $actor);
            }
            foreach (Project::query()->whereKey(array_keys($hidden['projects'] ?? []))->where('status', ListingStatus::Hidden)->get() as $project) {
                ($this->projectStatus)($project, ListingStatus::from($hidden['projects'][$project->id]), $actor);
            }
            foreach (SearchRequest::query()->whereKey(array_keys($hidden['requests'] ?? []))->where('status', RequestStatus::Hidden)->get() as $request) {
                $request->forceFill(['status' => RequestStatus::Published])->save();
                $this->index->request($request);
            }

            ConversationParticipant::query()
                ->whereIn('conversation_id', ConversationParticipant::query()->where('customer_id', $customer->id)->pluck('conversation_id'))
                ->where('customer_id', '!=', $customer->id)->where('hidden_at', $deletion->requested_at)
                ->update(['hidden_at' => null]);

            $deletion->forceFill(['cancelled_at' => now(), 'cancelled_by' => $by])->save();
            activity('accounts')->performedOn($customer)->event('deletion_cancelled')->withProperties(['by' => $by])->log('إلغاء حذف الحساب');
        });
    }
}
