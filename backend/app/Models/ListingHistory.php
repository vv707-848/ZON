<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of a listing's status, price or description — written once, never edited. */
class ListingHistory extends Model
{
    public $timestamps = false;

    protected $table = 'listing_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }
}
