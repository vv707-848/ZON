<?php

namespace App\Actions\Listings;

use App\Enums\CommissionStatus;
use App\Enums\ListingStatus;
use App\Enums\OwnerCaseStage;
use App\Models\CommissionDue;
use App\Models\Listing;
use App\Models\SaleReport;
use App\Support\Actor;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * «تم البيع» — final. An owner's listing makes his commission due (the case's percent of the sale price); the row
 * stays forever: what sold through Zon is the revenue model. Every sale is also reported to the market as said:
 * the price it sold for only when he gave it (SaleReport).
 */
final class MarkListingSold
{
    public function __construct(private readonly ChangeListingStatus $status) {}

    public function __invoke(Listing $listing, Actor $actor, ?string $salePrice = null): Listing
    {
        return DB::transaction(function () use ($listing, $actor, $salePrice) {
            ($this->status)($listing, ListingStatus::Sold, $actor);
            SaleReport::forListing($listing, $salePrice);

            $case = $listing->ownerCase()->first();
            if ($case !== null) {
                $price = $salePrice ?? (string) $listing->price;
                $halalas = BigDecimal::of($price)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
                CommissionDue::query()->create([
                    'customer_id' => $listing->customer_id,
                    'source_type' => $case->getMorphClass(),
                    'source_id' => $case->id,
                    'listing_id' => $listing->id,
                    'sale_price' => $price,
                    'percent' => $case->commission_percent,
                    'amount_halalas' => (new Money($halalas))->percent((string) $case->commission_percent)->halalas,
                    'status' => CommissionStatus::Due,
                ]);
                $case->forceFill(['stage' => OwnerCaseStage::Closed])->save();
            }

            return $listing;
        });
    }
}
