<?php

namespace App\Models;

use App\Enums\CommissionStatus;
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
}
