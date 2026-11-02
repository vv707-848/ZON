<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stars on an advertiser's public profile: one per pair, editable; staff may hide one. */
class Rating extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    /** @param Builder<Rating> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /** @return BelongsTo<Customer, $this> */
    public function rater(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'rater_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function rated(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'rated_id');
    }
}
