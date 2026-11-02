<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «حذف حسابي»: a grace period (Setting::DeletionGraceDays), cancelled by signing in, then anonymization. */
class DeletionRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'purge_at' => 'datetime', 'cancelled_at' => 'datetime', 'purged_at' => 'datetime', 'hidden_items' => 'array'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPending(): bool
    {
        return $this->cancelled_at === null && $this->purged_at === null;
    }
}
