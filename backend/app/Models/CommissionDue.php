<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A commission due after a sale: the owner's 0.5 %, a developer's per contract, a free broker's share. */
class CommissionDue extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'due'];

    protected function casts(): array
    {
        return ['status' => CommissionStatus::class, 'sale_price' => 'decimal:2', 'percent' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return MorphTo<Model, $this> owner_case · marketing_contract · listing */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    /** @return BelongsTo<ProjectUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProjectUnit::class, 'project_unit_id');
    }

    /** The commission on a sale at [price] riyals: [percent] of it, in halalas. */
    public static function amountFor(string $price, string $percent): int
    {
        $halalas = BigDecimal::of($price)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();

        return (new Money($halalas))->percent($percent)->halalas;
    }

    /** What the listing or the unit was offered at: beside the price the seller said it sold for. */
    public function listedPrice(): ?string
    {
        $price = $this->listing->price ?? $this->unit->price ?? null;

        return $price === null ? null : (string) $price;
    }
}
