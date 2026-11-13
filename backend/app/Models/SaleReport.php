<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A sale an advertiser marked, with the price he said it sold for when he said it (market reports: ai_v_sales). */
class SaleReport extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['listed_price' => 'decimal:2', 'sale_price' => 'decimal:2', 'count' => 'integer'];
    }

    public static function forListing(Listing $listing, ?string $salePrice): self
    {
        return self::query()->create([
            'customer_id' => $listing->customer_id,
            'subject_type' => $listing->getMorphClass(),
            'subject_id' => $listing->id,
            'count' => 1,
            'purpose' => $listing->purpose->value,
            'property_type' => $listing->property_type->value,
            'city' => $listing->city,
            'neighborhood' => $listing->neighborhood,
            'listed_price' => $listing->price,
            'sale_price' => $salePrice,
        ]);
    }

    public static function forUnits(ProjectUnit $unit, int $count, ?string $salePrice): self
    {
        $project = $unit->project;

        return self::query()->create([
            'customer_id' => $project->developer_id,
            'subject_type' => $unit->getMorphClass(),
            'subject_id' => $unit->id,
            'count' => $count,
            'purpose' => 'sale',
            'property_type' => ($unit->property_type ?? $project->property_type)->value,
            'city' => $project->city,
            'neighborhood' => $project->neighborhood,
            'listed_price' => $unit->price,
            'sale_price' => $salePrice,
        ]);
    }
}
