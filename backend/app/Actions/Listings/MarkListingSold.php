<?php

namespace App\Actions\Listings;

use App\Enums\CommissionStatus;
use App\Enums\ListingStatus;
use App\Enums\OwnerCaseStage;
use App\Models\CommissionDue;
use App\Models\Listing;
use App\Models\SaleReport;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * «تم البيع» — final. An owner's listing makes his commission due (the case's percent of the sale price); the row
 * stays forever: what sold through Zon is the revenue model. Every sale is also reported to the market as said:
 * the price it sold for only when he gave it (SaleReport).
 *
 * Once: the listing is read again under its row lock, and a listing already sold is answered as it is — no second
 * commission or report, whether the same request came again (its reply lost), from another device or at once.
 */
final class MarkListingSold
{
    public function __construct(private readonly ChangeListingStatus $status) {}

    public function __invoke(Listing $listing, Actor $actor, ?string $salePrice = null): Listing
    {
        return DB::transaction(function () use ($listing, $actor, $salePrice) {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            if ($listing->status === ListingStatus::Sold) {
                return $listing;
            }
            ($this->status)($listing, ListingStatus::Sold, $actor);
            SaleReport::forListing($listing, $salePrice);

            $case = $listing->ownerCase()->first();
            if ($case !== null) {
                $price = $salePrice ?? (string) $listing->price;
                CommissionDue::query()->create([
                    'customer_id' => $listing->customer_id,
                    'source_type' => $case->getMorphClass(),
                    'source_id' => $case->id,
                    'listing_id' => $listing->id,
                    'sale_price' => $price,
                    'percent' => $case->commission_percent,
                    'amount_halalas' => CommissionDue::amountFor($price, (string) $case->commission_percent),
                    'status' => CommissionStatus::Due,
                ]);
                $case->forceFill(['stage' => OwnerCaseStage::Closed])->save();
            }

            return $listing;
        });
    }
}
