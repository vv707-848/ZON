<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Private stock: never indexed, never in a public list. «نشر كإعلان» copies it into a normal listing. */
class InventoryItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose' => Purpose::class,
            'property_type' => PropertyType::class,
            'price' => 'decimal:2',
            'area_land' => 'decimal:2',
            'area_built' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'broker_id');
    }

    /** @return BelongsTo<Listing, $this> */
    public function publishedListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'published_listing_id')->withTrashed();
    }

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderByDesc('is_cover')->orderBy('sort');
    }
}
