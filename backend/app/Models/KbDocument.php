<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A document of the chat's knowledge library — out of retrieval once its review date has passed. */
class KbDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'next_review' => 'date', 'is_active' => 'boolean'];
    }

    /** @param Builder<KbDocument> $query */
    public function scopeRetrievable(Builder $query): void
    {
        $query->where('is_active', true)->whereDate('next_review', '>=', today());
    }
}
