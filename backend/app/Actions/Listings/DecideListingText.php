<?php

namespace App\Actions\Listings;

use App\Ai\Capture\TrainingCapture;
use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Enums\ReviewDecision;
use App\Enums\TextReview;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Models\Listing;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * The advertiser's decision on the AI wording: approve it, edit it (the edit is captured, masked, to improve the
 * wording), or keep his own text. A decision after the automatic publication changes the indexed text only.
 */
final class DecideListingText
{
    public function __construct(private readonly PublishListing $publish, private readonly IndexSync $index) {}

    public function __invoke(Listing $listing, ReviewDecision $decision, ?string $text): Listing
    {
        $open = $listing->desc_suggested !== null && in_array($listing->text_review, [TextReview::Ready, TextReview::Auto], true);
        if (! $open) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }

        return DB::transaction(function () use ($listing, $decision, $text) {
            [$clean, $review] = match ($decision) {
                ReviewDecision::Approve => [$listing->desc_suggested, TextReview::Approved],
                ReviewDecision::Edit => [trim((string) $text), TextReview::Edited],
                ReviewDecision::Reject => [null, TextReview::Rejected],
            };
            if ($decision === ReviewDecision::Edit) {
                TrainingCapture::textEdit((string) $listing->desc_suggested, $clean, $listing->customer);
            }
            $listing->forceFill(['desc_clean' => $clean, 'text_review' => $review, 'text_reviewed_at' => now()])->save();

            $listing->status === ListingStatus::PendingReview
                ? ($this->publish)($listing, Actor::customer($listing->customer))
                : $this->index->listing($listing);

            return $listing;
        });
    }
}
