<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\OwnerCaseStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The owner's path for one property: brokerage contract on REGA (approved through Nafath) → licence fee →
 * advertising licence → listing → active. Followed automatically every 10 minutes (SyncOwnerContracts).
 */
class OwnerCase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stage' => OwnerCaseStage::class,
            'contract_status' => ContractStatus::class,
            'contract_approved_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'commission_percent' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function feePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'fee_payment_id');
    }

    /** @return BelongsTo<AdLicense, $this> */
    public function licence(): BelongsTo
    {
        return $this->belongsTo(AdLicense::class, 'ad_license_id');
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }
}
