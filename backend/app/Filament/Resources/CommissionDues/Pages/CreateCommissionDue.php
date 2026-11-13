<?php

namespace App\Filament\Resources\CommissionDues\Pages;

use App\Enums\CommissionStatus;
use App\Enums\CustomerRole;
use App\Enums\ListingStatus;
use App\Filament\Resources\CommissionDues\CommissionDueResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\CommissionDue;
use App\Models\Listing;
use App\Models\Subscription;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A free broker's share: the given percent of the commission he received (not of the sale price). */
class CreateCommissionDue extends CreateRecord
{
    protected static string $resource = CommissionDueResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $fee = Money::sar((string) $this->data['brokerage_fee']);
        if ($fee->halalas > Money::sar((string) $data['sale_price'])->halalas) {
            throw ValidationException::withMessages(['data.brokerage_fee' => 'السعي لا يزيد على سعر البيع.']);
        }

        // what the list offers is checked again here (a crafted request can send any id), the listing held so two
        // staff recording it at once record it once
        return DB::transaction(function () use ($data, $fee) {
            $listing = Listing::query()->with('customer')->lockForUpdate()->find($data['listing_id']);
            if ($listing === null || $listing->status !== ListingStatus::Sold || $listing->customer?->role !== CustomerRole::Broker) {
                throw ValidationException::withMessages(['data.listing_id' => 'اختر عرضاً مباعاً لوسيط.']);
            }
            if (CommissionDue::query()->where('listing_id', $listing->id)->whereIn('status', [CommissionStatus::Due, CommissionStatus::Paid])->exists()) {
                throw ValidationException::withMessages(['data.listing_id' => 'سُجّلت حصة هذا البيع من قبل.']);
            }
            $soldAt = $listing->sold_at ?? now();
            $subscribed = Subscription::query()->where('customer_id', $listing->customer_id)->where('starts_at', '<=', $soldAt)
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $soldAt))
                ->where(fn (Builder $q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $soldAt))->exists();
            if ($subscribed) {
                throw ValidationException::withMessages(['data.listing_id' => 'كان الوسيط مشتركاً حين البيع؛ الحصة على الوسيط بلا اشتراك فقط.']);
            }

            return $this->record($listing, $data, $fee);
        });
    }

    /** @param array<string, mixed> $data */
    private function record(Listing $listing, array $data, Money $fee): CommissionDue
    {
        $due = CommissionDue::query()->create([
            'customer_id' => $listing->customer_id,
            'source_type' => $listing->getMorphClass(),
            'source_id' => $listing->id,
            'listing_id' => $listing->id,
            'sale_price' => $data['sale_price'],
            'percent' => $data['percent'],
            'amount_halalas' => $fee->percent((string) $data['percent'])->halalas,
            'status' => CommissionStatus::Due,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => Staff::user()->id,
        ]);
        Audit::log('commission_recorded', 'تسجيل حصة وسيط', $due);

        return $due;
    }
}
