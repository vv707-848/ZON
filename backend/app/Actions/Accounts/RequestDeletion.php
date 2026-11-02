<?php

namespace App\Actions\Accounts;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Projects\ChangeProjectStatus;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Indexing\IndexSync;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\DeletionRequest;
use App\Support\Actor;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * «حذف حسابي»: everything public disappears now, the account is purged after the grace period. What is hidden
 * is listed on the request (and the other sides' conversations get hidden_at = requested_at, to the second),
 * so a cancellation brings back exactly that and nothing a moderator hid meanwhile.
 */
final class RequestDeletion
{
    public function __construct(
        private readonly ChangeListingStatus $listingStatus,
        private readonly ChangeProjectStatus $projectStatus,
        private readonly IndexSync $index,
    ) {}

    public function __invoke(Customer $customer): DeletionRequest
    {
        return DB::transaction(function () use ($customer) {
            $at = now()->startOfSecond();
            $actor = Actor::customer($customer);
            $hidden = ['listings' => [], 'projects' => [], 'requests' => []];

            foreach ($customer->listings()->whereIn('status', [ListingStatus::Published, ListingStatus::Paused, ListingStatus::Inactive])->get() as $listing) {
                $hidden['listings'][$listing->id] = $listing->status->value;
                ($this->listingStatus)($listing, ListingStatus::Hidden, $actor);
            }
            foreach ($customer->projects()->whereIn('status', [ListingStatus::Published, ListingStatus::Paused])->get() as $project) {
                $hidden['projects'][$project->id] = $project->status->value;
                ($this->projectStatus)($project, ListingStatus::Hidden, $actor);
            }
            foreach ($customer->searchRequests()->where('status', RequestStatus::Published)->get() as $request) {
                $hidden['requests'][$request->id] = $request->status->value;
                $request->forceFill(['status' => RequestStatus::Hidden])->save();
                $this->index->request($request);
            }

            ConversationParticipant::query()
                ->whereIn('conversation_id', ConversationParticipant::query()->where('customer_id', $customer->id)->pluck('conversation_id'))
                ->where('customer_id', '!=', $customer->id)->whereNull('hidden_at')
                ->update(['hidden_at' => $at]);

            $customer->tokens()->delete();
            activity('accounts')->performedOn($customer)->causedBy($customer)->event('deletion_requested')->log('طلب حذف الحساب');

            return DeletionRequest::query()->create([
                'customer_id' => $customer->id,
                'requested_at' => $at,
                'purge_at' => $at->copy()->addDays(Settings::int(Setting::DeletionGraceDays)),
                'hidden_items' => $hidden,
            ]);
        });
    }
}
