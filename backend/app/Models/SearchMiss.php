<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A search that found nothing — the earliest signal of unmet demand. */
class SearchMiss extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['budget_min' => 'decimal:2', 'budget_max' => 'decimal:2'];
    }
}
