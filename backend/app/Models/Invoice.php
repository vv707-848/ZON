<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A simplified tax invoice (ZATCA phase 1), numbered without gaps per year. */
class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'lines' => 'array', 'vat_rate' => 'decimal:4'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
