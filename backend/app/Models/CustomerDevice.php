<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone of an account and its push token (FCM). */
class CustomerDevice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
