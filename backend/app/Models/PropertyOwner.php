<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An owner a broker deals with («إدارة الملاك»), private to that broker. */
class PropertyOwner extends Model
{
    protected $guarded = ['id'];

    /** @return BelongsTo<Customer, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'broker_id');
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    /** @return HasMany<OwnerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(OwnerNote::class, 'owner_id')->latest('id');
    }
}
