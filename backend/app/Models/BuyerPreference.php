<?php

namespace App\Models;

use App\Enums\Purpose;
use Database\Factories\BuyerPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A seeker's wish: required before entering the app (428), one when free, several with a plan. */
class BuyerPreference extends Model
{
    /** @use HasFactory<BuyerPreferenceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['purpose' => 'sale', 'is_active' => true, 'share_in_reports' => true];

    protected function casts(): array
    {
        return [
            'purpose' => Purpose::class,
            'neighborhoods' => 'array',
            'property_types' => 'array',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'is_active' => 'boolean',
            'share_in_reports' => 'boolean',
            'matches_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
