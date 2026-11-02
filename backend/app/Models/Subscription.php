<?php

namespace App\Models;

use App\Enums\SubscriptionSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A period of a plan: bought, granted by staff, or opened by a developer's marketing contract. */
class Subscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => SubscriptionSource::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'renewal_reminded_at' => 'datetime',
        ];
    }

    /** @param Builder<Subscription> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('cancelled_at')->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isCurrent(): bool
    {
        return $this->cancelled_at === null && $this->starts_at->isPast() && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** @return HasMany<BoostUsage, $this> quota used in this period */
    public function boosts(): HasMany
    {
        return $this->hasMany(BoostUsage::class);
    }
}
