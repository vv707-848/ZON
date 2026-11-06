<?php

namespace App\Actions\Listings;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\TextReview;
use App\Http\Requests\Api\Listings\ListingRequest;
use App\Indexing\IndexSync;
use App\Jobs\CleanListingText;
use App\Models\Listing;
use App\Models\ListingHistory;
use App\Support\Actor;
use App\Support\MediaLibrary;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Editing a listing keeps it where it is: a new description is shown as written until the advertiser decides on
 * the new wording (no flicker out of the results); a price change is written to the history; an inactive
 * listing that is updated comes back.
 */
final class UpdateListing
{
    public function __construct(private readonly IndexSync $index, private readonly ChangeListingStatus $status) {}

    /**
     * @param  array<string, mixed>  $columns
     * @param  ?list<int>  $mediaIds
     */
    public function __invoke(Listing $listing, Actor $actor, array $columns, ?array $mediaIds, ?int $coverId): Listing
    {
        return DB::transaction(function () use ($listing, $actor, $columns, $mediaIds, $coverId) {
            if (isset($columns['property_type']) && $columns['property_type'] !== $listing->property_type->value) {
                $uses = PropertyType::from($columns['property_type'])->fields();
                foreach (array_diff(ListingRequest::SPEC_FIELDS, $uses) as $field) {
                    $columns[$field] ??= null;            // what the new type does not use is cleared
                }
            }
            $newText = isset($columns['desc_raw']) && $columns['desc_raw'] !== $listing->desc_raw;
            $oldPrice = (string) $listing->price;

            $listing->fill($columns);
            if ($newText) {
                $listing->forceFill(['desc_clean' => null, 'desc_suggested' => null, 'text_review' => TextReview::Pending]);
            }
            $listing->forceFill(['last_activity_at' => now()])->save();

            if ($mediaIds !== null) {
                $listing->forceFill(['image_hash' => MediaLibrary::sync($listing, $listing->customer, $mediaIds, $coverId)])->save();
            }
            if (isset($columns['price']) && ! BigDecimal::of($oldPrice)->isEqualTo((string) $listing->price)) {
                ListingHistory::query()->create([
                    'listing_id' => $listing->id, 'field' => 'price', 'old_value' => $oldPrice, 'new_value' => (string) $listing->price,
                    'actor' => (string) $actor, 'changed_at' => now(),
                ]);
            }
            if ($newText) {
                CleanListingText::dispatch($listing->id)->afterCommit();
            }

            if ($listing->status === ListingStatus::Inactive) {
                ($this->status)($listing, ListingStatus::Published, $actor);
            } else {
                $this->index->listing($listing);
            }

            return $listing;
        });
    }
}
