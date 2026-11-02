<?php

namespace App\Jobs;

use App\Actions\Listings\ChangeListingStatus;
use App\Actions\Listings\PublishListing;
use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Http\Requests\Api\Listings\ListingRequest;
use App\Indexing\IndexSync;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Actor;
use App\Support\ArabicText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * The AI wording of a description (clean_listing, queue `ai`). The suggestion waits for the advertiser's decision
 * («بانتظار مراجعتك»). If the AI cannot answer, the listing is published as written (text_review = auto): the AI
 * never stands between an advertiser and his publication. An identical suggestion needs no decision.
 */
class CleanListingText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $listingId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiGateway $ai, PublishListing $publish, ChangeListingStatus $status, IndexSync $index): void
    {
        $listing = Listing::query()->with('customer')->find($this->listingId);
        if ($listing === null || $listing->text_review !== TextReview::Pending) {
            return;                                                   // deleted, or already decided meanwhile
        }

        $specs = array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v,
            array_filter($listing->only(ListingRequest::SPEC_FIELDS), fn ($v) => $v !== null));
        $result = $ai->call(AiAgent::CleanListing, [
            'type' => $listing->property_type->value,
            'city' => $listing->city,
            'neighborhood' => $listing->neighborhood,
            'text' => $listing->desc_raw,
        ] + ($specs === [] ? [] : ['fields' => $specs]),    // an empty PHP array goes out as a JSON list; the agents expect an object
            check: function (array $output): ?array {
                $text = trim((string) ($output['desc_clean'] ?? ''));

                return $text === '' ? null : ['desc_clean' => $text, 'features' => array_values(array_filter((array) ($output['features'] ?? []), 'is_string'))];
            }, customer: $listing->customer, cache: false);

        DB::transaction(function () use ($listing, $result, $publish, $status, $index) {
            $suggested = $result->output['desc_clean'] ?? null;
            $same = $suggested !== null && ArabicText::normalize($suggested) === ArabicText::normalize($listing->desc_raw);

            if ($suggested === null || $same) {
                $listing->forceFill(['text_review' => $same ? TextReview::Approved : TextReview::Auto, 'text_reviewed_at' => now()])->save();
                $listing->status === ListingStatus::Indexing ? $publish($listing, Actor::system()) : $index->listing($listing);

                return;
            }

            $listing->forceFill([
                'desc_suggested' => $suggested,
                'text_review' => TextReview::Ready,
                'features' => $listing->features ?: ($result->output['features'] ?: null),
            ])->save();
            if ($listing->status === ListingStatus::Indexing) {
                $status($listing, ListingStatus::PendingReview, Actor::system());
            }
            $listing->customer->notify(Notices::wordingReady($listing));
        });
    }
}
