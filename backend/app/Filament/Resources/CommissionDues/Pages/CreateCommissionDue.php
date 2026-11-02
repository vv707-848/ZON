<?php

namespace App\Filament\Resources\CommissionDues\Pages;

use App\Enums\CommissionStatus;
use App\Filament\Resources\CommissionDues\CommissionDueResource;
use App\Filament\Support\Audit;
use App\Filament\Support\Staff;
use App\Models\CommissionDue;
use App\Models\Listing;
use App\Support\Money;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** A free broker's share: the given percent of the commission he received (not of the sale price). */
class CreateCommissionDue extends CreateRecord
{
    protected static string $resource = CommissionDueResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $listing = Listing::query()->findOrFail($data['listing_id']);
        $fee = Money::sar((string) $this->data['brokerage_fee']);
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
