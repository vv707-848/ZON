<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Follow extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return BelongsTo<Customer, $this> */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'follower_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function followed(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'followed_id');
    }
}
