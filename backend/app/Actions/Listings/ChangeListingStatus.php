<?php

namespace App\Actions\Listings;

use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Models\Listing;
use App\Models\ListingHistory;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * The only way a listing changes status: the state machine (ListingStatus::canBecome), the history row and the
 * index signal, in one transaction. A move the machine does not allow is INVALID_TRANSITION.
 */
final class ChangeListingStatus
{
    public function __construct(private readonly IndexSync $index) {}

    /** @param array<string, mixed> $also other columns saved with the change (paused_until, …) */
    public function __invoke(Listing $listing, ListingStatus $to, Actor $actor, array $also = []): Listing
    {
        $from = $listing->status;
        if ($from === $to && $also === []) {
            return $listing;
        }
        if ($from !== $to && ! $from->canBecome($to)) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $from->value, 'to' => $to->value]);
        }

        return DB::transaction(function () use ($listing, $from, $to, $actor, $also) {
            $listing->forceFill([
                ...$also,
                'status' => $to,
                'published_at' => $to === ListingStatus::Published ? ($listing->published_at ?? now()) : $listing->published_at,
                'sold_at' => $to === ListingStatus::Sold ? now() : $listing->sold_at,
                'paused_until' => $to === ListingStatus::Paused ? ($also['paused_until'] ?? null) : null,
                'auto_hidden_at' => $also['auto_hidden_at'] ?? null,     // a person's decision replaces the reports'
                'last_activity_at' => now(),
            ])->save();

            if ($from !== $to) {
                ListingHistory::query()->create([
                    'listing_id' => $listing->id,
                    'field' => 'status',
                    'old_value' => $from->value,
                    'new_value' => $to->value,
                    'actor' => (string) $actor,
                    'changed_at' => now(),
                ]);
            }
            $this->index->listing($listing);

            return $listing;
        });
    }
}
