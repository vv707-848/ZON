<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** «إعجاب» on a listing or a project. */
class Favorite extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return MorphTo<Model, $this> */
    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }
}
