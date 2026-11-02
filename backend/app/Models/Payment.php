<?php

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One payment (payment_transactions). Priced by the server at checkout; paid only after the provider itself
 * confirms the amount (App\Actions\Billing\ConfirmPayment). `public_id` is what the app and the provider see.
 */
class Payment extends Model
{
    use HasUlids;

    protected $table = 'payment_transactions';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'currency' => 'SAR'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function amount(): Money
    {
        return new Money($this->amount_halalas);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return MorphTo<Model, $this> what it pays for: a package, a request, a listing, an owner case */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasOne<Invoice, $this> */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'payment_id');
    }
}
