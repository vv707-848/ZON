<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One account blocking another — the blocked side is never told (App\Support\Blocks applies it in queries). */
class UserBlock extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return BelongsTo<Customer, $this> */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'blocker_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'blocked_id');
    }
}
